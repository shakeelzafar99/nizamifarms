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
  /* Unit selector: NF+Frozen default button + a Business-units dropdown for the single units. */
  #hq-root .unitsel{display:flex;align-items:center;gap:8px}
  #hq-root .useg{display:flex;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px}
  #hq-root .useg button{border:0;background:none;font:inherit;font-size:12.5px;font-weight:600;color:var(--mut);
    padding:6px 13px;border-radius:7px;cursor:pointer;display:flex;align-items:center;gap:7px;white-space:nowrap}
  #hq-root .useg button .udot{width:8px;height:8px;border-radius:50%;background:var(--u-all);flex:0 0 auto}
  #hq-root .useg button.on{background:var(--ink);color:#fff}
  #hq-root .useg button.on .udot{outline:2px solid rgba(255,255,255,.45)}
  #hq-root .budrop{position:relative}
  #hq-root .budrop-btn{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:10px;
    font:inherit;font-size:12.5px;font-weight:600;color:var(--mut);padding:9px 13px;cursor:pointer;white-space:nowrap}
  #hq-root .budrop-btn.on{background:var(--ink);color:#fff;border-color:var(--ink)}
  #hq-root .budrop-btn .cav{font-size:10px;opacity:.7}
  #hq-root .budrop-menu{position:absolute;top:calc(100% + 6px);left:0;z-index:30;min-width:190px;background:#fff;
    border:1px solid var(--line);border-radius:11px;box-shadow:0 12px 34px rgba(28,25,23,.16);padding:5px;display:none}
  #hq-root .budrop-menu.on{display:block}
  #hq-root .budrop-menu button{display:flex;align-items:center;gap:9px;width:100%;border:0;background:none;font:inherit;
    font-size:12.5px;font-weight:600;color:var(--ink);padding:9px 11px;border-radius:8px;cursor:pointer;text-align:left}
  #hq-root .budrop-menu button:hover{background:var(--card)}
  #hq-root .budrop-menu button.on{background:var(--card)}
  #hq-root .budrop-menu button .udot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
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
  #hq-root .kpis{grid-template-columns:repeat(4,1fr)}
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

  /* 6 boxes: revenue − vendor = GP − expenses = net profit ┊ assets bought.
     The last one is an ASIDE (capital spending), not part of the equation —
     hence a divider rather than an operator before it. */
  #hq-root .pl-flow{display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto 1fr auto 1fr auto 1fr auto 1fr;align-items:stretch}
  #hq-root .pl-cell{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:13px 15px;min-width:0}
  #hq-root .pl-op{display:flex;align-items:center;justify-content:center;color:var(--faint);font-size:16px;font-weight:600;padding:0 7px}
  #hq-root .pl-cell .k-val{font-size:18px}
  #hq-root .pl-cell.result{border-color:var(--line2);background:#fbfaf9}
  #hq-root .pl-cell .ratio{font-size:11px;font-weight:700;color:var(--mut);margin-top:4px;font-variant-numeric:tabular-nums}
  #hq-root .pl-bar{display:flex;height:14px;border-radius:7px;overflow:hidden;margin-top:14px;border:1px solid var(--line)}
  #hq-root .pl-bar .seg-v{height:100%}
  #hq-root .pl-legend{display:flex;gap:18px;flex-wrap:wrap;margin-top:8px;font-size:11.5px;color:var(--mut)}
  #hq-root .pl-legend .sw{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:-.5px}
  /* Divider before the aside box — deliberately NOT an operator glyph. */
  #hq-root .pl-sep{display:flex;align-items:center;justify-content:center;padding:0 9px}
  #hq-root .pl-sep::before{content:'';width:0;height:64%;border-left:1px dashed var(--line2)}
  #hq-root .pl-cell.aside{background:#faf9f8;border-style:dashed}
  #hq-root .pl-cell.aside .k-val{color:var(--mut)}

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
  #hq-root .netpos .health .h-row{color:#d6d3d1}
  /* the arithmetic behind Net working capital, shown on the card itself */
  #hq-root .nwcf{margin-top:9px;padding-top:9px;border-top:1px solid #44403c;
                 display:flex;flex-direction:column;gap:4px;font-size:11.5px}
  #hq-root .nwcf .f-row{display:flex;align-items:baseline;gap:8px;color:#d6d3d1;font-variant-numeric:tabular-nums}
  #hq-root .nwcf .f-op{width:9px;flex:0 0 auto;color:#a8a29e;font-weight:700}
  #hq-root .nwcf .f-val{margin-left:auto;white-space:nowrap}
  #hq-root .nwcf .f-sum{border-top:1px solid #44403c;margin-top:3px;padding-top:6px;color:#fff;font-weight:700}
  #hq-root .wc-note{font-size:11px;color:var(--faint);margin-top:6px}

  #hq-root .trend-card svg{display:block;width:100%;height:150px}
  #hq-root .t-legend{display:flex;gap:18px;font-size:11.5px;color:var(--mut);margin-top:6px}

  /* ---------- growth: recency ladder + cohort ---------- */
  #hq-root .gnote{font-size:11.5px;color:var(--mut);margin-top:11px;line-height:1.55}
  #hq-root .lrow{display:grid;grid-template-columns:104px 1fr 128px;gap:12px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line)}
  #hq-root .lrow:last-child{border-bottom:0}
  #hq-root .lrow.click{cursor:pointer;border-radius:7px;transition:background .12s}
  #hq-root .lrow.click:hover{background:#faf9f8}
  #hq-root .lrow.wb{background:#fffbeb}
  #hq-root .ln{font-size:12px;font-weight:600;color:var(--mut);display:flex;align-items:center;gap:6px}
  #hq-root .lb{height:20px;border-radius:5px;min-width:3px}
  #hq-root .lv{font-size:12.5px;font-weight:700;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  #hq-root .lv small{color:var(--mut);font-weight:600}
  #hq-root .wbtag{font-size:9px;font-weight:800;letter-spacing:.04em;color:#92400e;background:#fde68a;border-radius:4px;padding:1px 5px}
  #hq-root table.coh{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums;min-width:320px}
  #hq-root .coh th{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mut);text-align:center;padding:6px 6px;border-bottom:1px solid var(--line)}
  #hq-root .coh th:first-child,#hq-root .coh td:first-child{text-align:left}
  #hq-root .coh td{font-size:12px;padding:5px 6px;text-align:center;border-bottom:1px solid #f4f3f1}
  #hq-root .coh td:first-child{font-weight:600;color:var(--ink);white-space:nowrap}
  #hq-root .coh .cell{display:inline-block;min-width:42px;padding:4px 0;border-radius:5px;font-weight:700}
  #hq-root .coh .csize{color:var(--mut);font-weight:600}
  #hq-root .coh .cempty{color:#d6d3d1}

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
    #hq-root #hqPCats{grid-template-columns:repeat(2,1fr)!important}
    #hq-root #hqPTwoCol{grid-template-columns:1fr!important}
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
    <button class="modtab" id="tabProducts" type="button" onclick="hqSetTab('products')">Products</button>
    <button class="modtab soon" type="button" title="Planned">Fulfilment <small>SOON</small></button>
  </nav>
  <button class="refresh" type="button" onclick="hqReload(true)">↻ Refresh</button>
</div>

<div class="controls">
  <div class="unitsel" aria-label="Business unit">
    <div class="useg">
      <button class="useg-main on" type="button" onclick="hqSetUnit('all')"><span class="udot"></span>NF + Frozen</button>
    </div>
    <div class="budrop">
      <button class="budrop-btn" id="hqBuBtn" type="button" onclick="hqToggleBu(event)">Business units <span class="cav">▾</span></button>
      <div class="budrop-menu" id="hqBuMenu" role="menu">
        <button data-u="nf" type="button" role="menuitem" onclick="hqSetUnit('nf')"><span class="udot" style="background:var(--u-nf)"></span>Nizami Farms only</button>
        <button data-u="kh" type="button" role="menuitem" onclick="hqSetUnit('kh')"><span class="udot" style="background:var(--u-kh)"></span>Khaas · Frozen only</button>
        <button data-u="qb" type="button" role="menuitem" onclick="hqSetUnit('qb')"><span class="udot" style="background:var(--u-qb)"></span>Qurbani</button>
      </div>
    </div>
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

{{-- ===================== GROWTH ===================== --}}
<div id="hqGrowth" style="display:none">
  <h2 class="sec">Sales quality <span id="hqGrowthUnit" class="hint"></span><span class="rule"></span><span class="hint">follows the unit above</span></h2>
  <div class="grid" id="hqGSales" style="grid-template-columns:repeat(3,1fr)"></div>

  <div id="hqGRegular">
    <h2 class="sec">Customer engine — whole regular base <span class="rule"></span><span class="hint">one customer base (fresh + frozen) — not affected by the unit selector</span></h2>
    <div class="grid" id="hqGActives" style="grid-template-columns:repeat(3,1fr)"></div>

    <div class="two-col" style="display:grid;grid-template-columns:1.15fr 1fr;gap:12px;align-items:start;margin-top:12px">
      <div class="card">
        <div class="k-label">Customer recency — days since last order
          <button class="i-btn" type="button" onclick="hqDef('recency_def')" aria-label="How is recency calculated?">i</button>
          <span class="drill">click a band ›</span></div>
        <div id="hqLadder" style="margin-top:10px"></div>
        <div class="gnote" id="hqLadderNote"></div>
      </div>
      <div class="card">
        <div class="k-label">Cohort retention — % who ordered again
          <button class="i-btn" style="margin-left:auto" type="button" onclick="hqDef('cohort_def')" aria-label="How is cohort retention calculated?">i</button></div>
        <div id="hqCohort" style="margin-top:8px;overflow-x:auto"></div>
        <div class="gnote">Read down a column: if <strong>M+1</strong> rises cohort-over-cohort, your first-delivery experience is improving.</div>
      </div>
    </div>
  </div>

  <div id="hqGSeason" style="display:none">
    <h2 class="sec">Qurbani season — customers <span class="rule"></span><span class="hint">seasonal, not rolling</span></h2>
    <div class="grid" id="hqSeasonCards" style="grid-template-columns:repeat(4,1fr)"></div>

    <h2 class="sec" style="margin-top:24px">Qurbani → regular meat <span class="rule"></span>
      <span class="hint">who they were, and did they stay</span></h2>
    <div class="gnote" id="hqQbMixLede" style="margin-top:0;margin-bottom:10px"></div>
    <div class="grid" id="hqQbMix" style="grid-template-columns:repeat(2,1fr)"></div>
  </div>
</div>

{{-- ===================== PRODUCTS ===================== --}}
<div id="hqProducts" style="display:none">
  <h2 class="sec">What sold — by category <span class="rule"></span><span class="hint" id="hqPCatsHint">click a category for its products</span></h2>
  <div class="gnote" id="hqPQuality" style="display:none;margin:0 0 10px"></div>
  <div class="grid" id="hqPCats" style="grid-template-columns:repeat(4,1fr)"></div>

  <h2 class="sec">Top products <span class="rule"></span><span class="hint">click a row for its days</span></h2>
  <div class="card" style="padding:6px 10px"><div id="hqPTop" style="overflow-x:auto"></div></div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start;margin-top:18px" id="hqPTwoCol">
    <div class="card">
      <div class="k-label">Movers — vs last month
        <button class="i-btn" type="button" onclick="hqDef('p_movers')" aria-label="How are movers calculated?">i</button></div>
      <div id="hqPMovers" style="margin-top:8px"></div>
    </div>
    <div class="card">
      <div class="k-label">Product lifecycle
        <button class="i-btn" type="button" onclick="hqDef('p_life')" aria-label="How is lifecycle calculated?">i</button></div>
      <div id="hqPLife" style="margin-top:8px"></div>
    </div>
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
  var state={unit:'all',year:CUR_YEAR,month:CUR_MONTH,tab:'closing'}; // default = NF + Frozen combined (owner rule)
  var data={closing:null,trend:null,wc:null,growth:null,growthKey:null};
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
      // Two pills: last season + this season (2025 exists via the product
      // backfill; older years have no data in the system).
      rail.innerHTML=[CUR_YEAR-1,CUR_YEAR].map(function(y){
        return '<button class="mpill'+(state.year===y?' on':'')+'" type="button" onclick="hqPick('+y+','+state.month+')">Qurbani '+y+' season</button>';
      }).join('');
    }else{
      rail.innerHTML=MONTHS.map(function(mo){var on=(mo.y===state.year&&mo.m===state.month);
        return '<button class="mpill'+(on?' on':'')+'" type="button" onclick="hqPick('+mo.y+','+mo.m+')">'+mo.label+'</button>';}).join('');
    }
    // Unit selector: highlight either the NF+Frozen button (all) or the Business-units
    // dropdown (a single unit is selected), and reflect the current choice on the button.
    var isAll=state.unit==='all';
    var mainBtn=document.querySelector('#hq-root .useg-main');
    if(mainBtn)mainBtn.classList.toggle('on',isAll);
    var buBtn=$('hqBuBtn');
    if(buBtn){ buBtn.classList.toggle('on',!isAll);
      buBtn.innerHTML=(isAll?'Business units':esc(UNITS[state.unit].name))+' <span class="cav">▾</span>'; }
    document.querySelectorAll('#hqBuMenu button').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-u')===state.unit);});
    document.documentElement.style.setProperty('--accent',UNITS[state.unit].hex);
    $('hq-root')&&($('hq-root').style.setProperty('--accent',UNITS[state.unit].hex));
  }
  // Business-units dropdown open/close.
  window.hqToggleBu=function(ev){ if(ev)ev.stopPropagation(); var m=$('hqBuMenu'); if(m)m.classList.toggle('on'); };
  document.addEventListener('click',function(ev){ var m=$('hqBuMenu'); if(m&&m.classList.contains('on')&&!ev.target.closest('.budrop'))m.classList.remove('on'); });
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
    // Open month compares the elapsed window to the SAME window last month
    // (the backend mirrors it); closed months are full-vs-full.
    var cmp=c.is_open?'vs same days last month':'vs last month';
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
      : deltaChip(cur.revenue,prev.revenue)+' '+cmp;
    var ordLabel = isQb ? 'Orders booked' : 'Orders delivered';
    var ordSub   = isQb
      ? deltaChip(cur.orders,prev.orders)+' · '+rsS(cur.delivered_orders)+' delivered · AOV '+rs(dv.aov)
      : deltaChip(cur.orders,prev.orders)+' · ≈'+dv.orders_per_work_day+' / day · AOV '+rs(dv.aov);
    // Compact order-source split inside the Orders Delivered card. Shown only on
    // the overall NF+Frozen unit (c.unit==='all'), where split.total == this
    // card's order count so the two always reconcile; hidden on single business
    // units and Qurbani (origin split is company-wide, non-Qurbani). data.split
    // is fetched alongside closing in hqReload; guarded so a missing/failed split
    // simply omits the line and never disturbs the card.
    var sp = data.split;
    if (!isQb && c.unit === 'all' && sp && sp.total) {
      ordSub += '<div style="margin-top:5px;display:flex;gap:9px;flex-wrap:wrap;font-size:11px;font-weight:700" '
        + 'title="Order sources — App '+sp.app.count+' ('+sp.app.pct+'%) · Web '+sp.web.count+' ('+sp.web.pct+'%) · Manual/NF '+sp.manual.count+' ('+sp.manual.pct+'%)">'
        + '<span style="color:#2563eb">📱 '+Number(sp.app.count).toLocaleString()+'</span>'
        + '<span style="color:#0891b2">🌐 '+Number(sp.web.count).toLocaleString()+'</span>'
        + '<span style="color:#64748b">✍️ '+Number(sp.manual.count).toLocaleString()+'</span>'
        + '</div>';
    }
    // Gross & Net profit intentionally live ONLY in the P&L flow below (each
    // with its own ⓘ) — this row is the activity/scale snapshot, no duplication.
    var cards=[
      card('rev',revLabel,rs(cur.revenue),revSub,'revenue'),
      card('ord',ordLabel,rsS(cur.orders),ordSub,'revenue'),
      card('kg',isQb?'Quantity booked':'Quantity sold',qtyVal,qtySub,'revenue'),
      card('cus','Customers ordered',rsS(cur.customers),'<span class="delta up">+'+rsS(cur.new_customers)+' new</span> first-time','customers')
    ];
    $('hqKpis').innerHTML=cards.join('');

    // P&L flow
    var flow, plHint;
    if(isQb){
      plHint='Booked revenue − Qurbani expenses = Season net profit (no vendor step — Qurbani has no vendors)';
      flow=[{l:'Booked revenue',v:cur.revenue,d:'revenue',def:'rev'},{op:'−'},
            {l:'Qurbani expenses (incl. animals)',v:cur.expenses,d:'expense',def:'qbcost'},{op:'='},
            {l:'Season net profit',v:cur.net_profit,r:dv.np_margin+'% NP margin',res:1,d:'expense',def:'np'}];
    }else{
      plHint='Revenue − Vendor purchases = Gross profit · − Expenses − Salaries = Net profit · assets shown separately';
      var an=cur.asset_purchases_count||0;
      flow=[{l:'Delivered revenue',v:cur.revenue,d:'revenue',def:'rev'},{op:'−'},
            {l:'Vendor purchases',v:cur.vendor_purchases,d:'vendor',def:'vendor'},{op:'=',brk:1},
            {l:'Gross profit',v:cur.gross_profit,r:dv.gp_margin+'% GP margin',res:1,d:'vendor',def:'gp'},{op:'−'},
            {l:'Expenses',v:cur.expenses,d:'expense',def:'expense'},{op:'−'},
            {l:'Salaries',v:cur.salaries||0,d:'salaries',def:'salary'},{op:'=',brk:1},
            {l:'Net profit',v:cur.net_profit,r:dv.np_margin+'% NP margin · '+rs(dv.net_per_work_day)+'/work day',res:1,d:'salaries',def:'np'},
            // Aside — capital spending sits OUTSIDE the equation (a bought asset
            // is property, not a cost), so it gets a divider, not an operator.
            {sep:1},
            {l:'Assets bought',v:cur.asset_purchases||0,aside:1,d:'monthassets',def:'monthasset',
             r:(an>0?an+' purchase'+(an===1?'':'s'):'nothing bought')+' · not in profit'}];
    }
    $('hqPlHead').querySelector('.hint').textContent=plHint;
    $('hqPlFlow').style.gridTemplateColumns=isQb?'1fr auto 1fr auto 1fr':'';
    $('hqPlFlow').innerHTML=flow.map(function(f,i){
      if(f.op)return '<div class="pl-op'+(f.brk?' brk':'')+'">'+f.op+'</div>';
      if(f.sep)return '<div class="pl-sep" aria-hidden="true"></div>';
      var iBtn=f.def?'<button class="i-btn" type="button" onclick="event.stopPropagation();hqDef(\''+f.def+'\')" aria-label="How is '+esc(f.l)+' calculated?">i</button>':'';
      return '<div class="pl-cell'+(f.res?' result':'')+(f.aside?' aside':'')+' card click" tabindex="0" role="button" onclick="hqDrill(\''+f.d+'\')">'+
        '<div class="k-label">'+esc(f.l)+iBtn+'</div><div class="k-val">'+rs(f.v)+'</div>'+
        (f.r?'<div class="ratio">'+f.r+'</div>':'')+'</div>';
    }).join('');

    var segs=[['Vendor purchases',cur.vendor_purchases,'#d6d3d1'],['Expenses',cur.expenses,'#a8a29e'],
              ['Salaries',cur.salaries||0,'#c9a227'],
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
    // The card shows the arithmetic that produced the number, so the four
    // inputs can be checked against their own cards without opening the "i".
    // NOTE: cash uses cash_total (ALL regular tills), not the cash card's
    // headline — that headline is NF_CASH only (owner rule), so the two
    // deliberately differ. Health rows below are WARNINGS ONLY; the "all
    // clear" lines were noise on a money card.
    var fRows=[['+', isQb?'Qurbani cash':'Cash · all tills', w.cash_total],
               ['+', isQb?'Qurbani online':'Online · ledger', w.online],
               ['+', isQb?'Season receivables':'Receivables', r.total]];
    if(!isQb) fRows.push(['−','Payables', w.payables]);
    var formula=fRows.map(function(x){
        return '<div class="f-row"><span class="f-op">'+x[0]+'</span><span>'+x[1]+'</span><span class="f-val">'+rsS(x[2])+'</span></div>';
      }).join('')+
      '<div class="f-row f-sum"><span class="f-op">=</span><span>'+(isQb?'Net position':'Net working capital')+'</span><span class="f-val">'+rsS(w.net)+'</span></div>';
    var warnings=(w.health||[]).filter(function(h){return !h.ok;});
    var warnHtml=warnings.length?'<div class="health">'+warnings.map(function(h){
        if(h.drill){
          return '<div class="h-row h-click" tabindex="0" role="button" onclick="hqDrill(\''+h.drill+'\')" onkeydown="if(event.key===\'Enter\')hqDrill(\''+h.drill+'\')" style="cursor:pointer"><span class="h-dot h-warn"></span><span style="text-decoration:underline dotted;text-underline-offset:2px">'+esc(h.label)+' ›</span></div>';
        }
        return '<div class="h-row"><span class="h-dot h-warn"></span>'+esc(h.label)+'</div>';
      }).join('')+'</div>':'';
    html+='<div class="card netpos"><div class="k-label">Net working capital'+iBtn('net')+'</div><div class="k-val">'+rs(w.net)+'</div>'+
      '<div class="nwcf">'+formula+'</div>'+warnHtml+'</div>';
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
        ['New','<span class="var">'+rsS(cur.new_customers)+'</span> received their <span class="var">first-ever delivery</span> in this window — counted on the day the order was <span class="var">delivered</span>, not the day it was placed'],
        ['Unit rule',unitRule]]},
      vendor:{t:'Vendor purchases',v:rs(cur.vendor_purchases),r:[
        ['Formula','Sum of <span class="var">approved / L2</span> vendor purchase bills dated in the window'],
        ['Window','Bill date within <span class="var">'+win+'</span>'],
        ['Unit rule',unitRule],
        ['Honest caveat','Purchases-based, not stock-matched — heavy month-end buying inflates it and corrects next month']]},
      gp:{t:'Gross profit',v:rs(cur.gross_profit),r:[
        ['Formula','Delivered revenue − Vendor purchases'],
        ['Values','<span class="var">'+rs(cur.revenue)+'</span> − <span class="var">'+rs(cur.vendor_purchases)+'</span> = <span class="var">'+rs(cur.gross_profit)+'</span> ('+dv.gp_margin+'%)'],
        ['Vendor purchases','Approved / L2 vendor bills dated in the month for '+U+' vendors'],
        ['Honest caveat','This is <span class="var">purchases-based</span> GP, not stock-matched cost — heavy month-end buying dips it, and it corrects next month.']]},
      expense:{t:'Expenses',v:rs(cur.expenses),r:[
        ['Formula','Sum of <span class="var">approved / L2</span> expense ledger entries dated in the window'],
        ['Window','Expense date within <span class="var">'+win+'</span>'],
        ['Unit rule',unitRule]]},
      qbcost:{t:'Qurbani costs',v:rs(cur.expenses),r:[
        ['Formula','Sum of approved <span class="var">Qurbani-category</span> expenses in the season'],
        ['Includes','Animal purchases (booked as expenses — Qurbani has no vendor ledger), slaughter, transport, ops'],
        ['Season','Expense date within <span class="var">'+win+'</span>']]},
      salary:{t:'Salaries',v:rs(cur.salaries||0),r:[
        ['Formula','Salaries <span class="var">actually paid</span> this month — the Payroll screen payments plus any legacy salary slips'],
        ['Window','Paid date within <span class="var">'+win+'</span> (cash basis, same as the Expenses page)'],
        ['Unit rule','Tagged to the <span class="var">business unit set on each employee</span> in Payroll → ⚙ settings. NF + Frozen shows everyone; a single unit shows just its staff'],
        ['Not in Expenses','Kept as its own line so the request-based Expenses figure stays clean — both reduce net profit']]},
      np:{t:'Net profit',v:rs(cur.net_profit),r:[
        ['Formula',isQb?'Revenue − Qurbani expenses':'Gross profit − Expenses − Salaries'],
        ['Values',(isQb?'<span class="var">'+rs(cur.revenue)+'</span> − <span class="var">'+rs(cur.expenses)+'</span>'
                       :'<span class="var">'+rs(cur.gross_profit)+'</span> − <span class="var">'+rs(cur.expenses)+'</span> − <span class="var">'+rs(cur.salaries||0)+'</span>')+' = <span class="var">'+rs(cur.net_profit)+'</span> ('+dv.np_margin+'%)'],
        ['Per work day',rs(cur.net_profit)+' ÷ <span class="var">'+c.working_days+' working days</span> (calendar − holidays) = <span class="var">'+rs(dv.net_per_work_day)+'</span>']]},
      monthasset:{t:'Assets bought',v:rs(cur.asset_purchases||0),r:[
        ['Formula','Sum of <span class="var">approved / L2</span> asset-purchase ledger entries dated in the <span class="var">full calendar month</span> — <span class="var">'+rsS(cur.asset_purchases_count||0)+'</span> this period. Click the box for the list'],
        ['Why it sits apart','Capital spending is <span class="var">not</span> part of Revenue − Vendor − Expenses: the money became equipment you still own, so it never reduces profit. That is why it has a divider, not a − sign'],
        ['Where the money went','It <span class="var">did</span> leave your cash / bank — so profit can look healthy while the account is lower. This box is the usual explanation'],
        ['Not the same as','<span class="var">Fixed assets</span> in Working capital = book value of everything you own; this box = only what was bought in this month'],
        ['Reconciles with','The <span class="var">Reports</span> tab’s “Assets” figure for the same month'],
        ['Unit rule',unitRule]]},
      p_cat:{t:'Product categories',v:'',r:[
        ['Formula','Line items of <span class="var">delivered</span> orders in the window, summed by product category — the same rules as the closing (non-Shopify, must still be delivered, <span class="var">Qurbani excluded</span> — it has its own view)'],
        ['Why it reads below revenue','Categories sum the <span class="var">product lines</span>; delivery charges and tips sit on the order, not on any product — so the category total is slightly below Delivered revenue by design'],
        ['Grouping','Categories group the catalog’s product types (cuts like “Back Chops” roll into <span class="var">Mutton</span>, etc.). The drill shows each product’s raw type — if something sits in the wrong bucket, say so and it’s a one-line fix'],
        ['Quantity','kg for weight-sold lines; frozen packs / per-piece items count as <span class="var">pieces</span> (same rule as Quantity sold)'],
        ['Penetration','“In N orders · X% of all” = share of the month’s orders containing this category'],
        ['Old months','Before <span class="var">March 2026</span> many items no longer link to the catalog — they appear as “Old catalog · unlinked” rather than being hidden'],
        ['Unit rule','Follows the unit selector at line level (NULL unit = NF)']]},
      p_movers:{t:'Movers',v:'',r:[
        ['Formula','Each product’s revenue this window vs the previous one (same-days for an open month); the biggest gains and drops'],
        ['Noise floor','Moves under <span class="var">Rs 5,000</span> are ignored'],
        ['Watch for','A top seller sliding two months in a row — price, stock, or quality usually explains it']]},
      p_life:{t:'Product lifecycle',v:'',r:[
        ['First sale','Products whose <span class="var">first-ever delivered sale</span> happened this month (by order date)'],
        ['Quiet listings','ACTIVE catalog products with no delivered sale in <span class="var">60+ days</span> — candidates to push, reprice or delist. Qurbani products are excluded (seasonal, not “dead”)']]}
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

    // ---- Growth definitions (read from data.growth) ----
    var g=data.growth;
    if(g){
      var gs=g.sales, ge=g.engine, gqb=g.is_season;
      D.g_aov={t:'Average order value',v:rs(gs.aov),r:[
        ['Formula',(gqb?'Booked revenue':'Delivered revenue')+' ÷ orders (from the Closing view, for '+esc(UNITS[state.unit].name)+')'],
        ['vs last '+(gqb?'season':'month'),'was <span class="var">'+rs(gs.aov_prev)+'</span>']]};
      D.g_new={t:'New customers',v:rsS(gs.new_customers),r:[
        ['Formula','Customers who received their <span class="var">first-ever delivery</span> in this period'],
        ['Clock','Counted on the day the order was <span class="var">delivered</span> — the same clock as revenue and every other number here. Someone who orders on the 30th and receives on the 2nd is new in the month they <span class="var">received</span> it'],
        ['Qurbani','A customer whose only earlier purchase was <span class="var">Qurbani</span> counts as new here on their first regular-meat delivery — Qurbani is a separate business and is excluded from this view (owner rule). The Qurbani tab shows the full new/existing + conversion split'],
        ['Unit','For '+esc(UNITS[state.unit].name)+' · was '+rsS(gs.new_prev)+' last '+(gqb?'season':'month')]]};
      D.g_repeat={t:'Repeat rate',v:gs.repeat+'%',r:[
        ['Formula','(customers − new) ÷ customers this '+(gqb?'season':'month')],
        ['Values','('+rsS(gs.customers)+' − '+rsS(gs.new_customers)+') ÷ '+rsS(gs.customers)+' = <span class="var">'+gs.repeat+'%</span>'],
        ['Meaning','Of everyone who bought, how many had bought before — the best single “is the base compounding?” number']]};
      if(!gqb&&ge){
        var mk=function(days){return {t:'Active customers · '+days+' days',v:rsS(ge['active_'+days]),r:[
          ['Formula','Unique customers (whole regular base) with ≥1 <span class="var">delivered</span> order in the last '+days+' days'],
          ['Window','rolling — counted back from <strong>today</strong>, not the month picker'],
          ['Note','Always ≥ the shorter window; base = '+rsS(ge.total_customers)+' customers who ever ordered']]};};
        D.g_a30=mk('30'); D.g_a60=mk('60'); D.g_a90=mk('90');
        D.recency_def={t:'Customer recency bands',v:'',r:[
          ['Formula','Every regular customer bucketed by days between their <span class="var">last delivered order</span> and <span class="var">today</span>'],
          ['Bands','0–30 · 31–60 · <strong>61–90 (win-back)</strong> · 91–180 · 180+ (lapsed)'],
          ['Why 61–90','Still warm, one nudge from lapsing — click the band for the exact WhatsApp list (name, phone, lifetime value)']]};
        D.cohort_def={t:'Cohort retention',v:'',r:[
          ['Formula','Take everyone whose <span class="var">first-ever delivery</span> was in month M. M+1 = % of them with ≥1 delivered order in the <span class="var">next month</span>, and so on'],
          ['Clock','Same delivery clock as New customers, so a month’s cohort <span class="var">size</span> equals its New-customers card'],
          ['Reading','Down a column: M+1 rising cohort-over-cohort = onboarding / first delivery improving'],
          ['—','Blank cells are months that haven’t finished yet']]};
      }
      if(gqb&&ge){
        D.g_season={t:'Season buyers',v:rsS(ge.buyers),r:[['Formula','Unique customers with a non-cancelled Qurbani order this season']]};
        D.g_seasonnew={t:'New to Qurbani',v:rsS(ge.new_to_season),r:[['Formula','Season buyers who did NOT buy Qurbani with us last season']]};
        D.g_returning={t:'Returning',v:rsS(ge.returning),r:[['Formula','Season buyers who also bought Qurbani last season']]};
        D.g_retention={t:'Season retention',v:ge.retention_pct+'%',r:[['Formula','Returning ÷ Season buyers = '+rsS(ge.returning)+' ÷ '+rsS(ge.buyers)+' = <span class="var">'+ge.retention_pct+'%</span>'],
          ['Last season','That year’s <span class="var">QUR-numbered</span> orders — or, for 2025, the Qurbani orders identified by <span class="var">product</span> (backfilled Jul 2026)']]};
        var gm=ge.mix||{buyers:0,new:{total:0,regular:0,only:0,pct:0},existing:{total:0,regular:0,only:0,pct:0}};
        D.g_qbnew={t:'New to Nizami Farms',v:rsS(gm.new.total),r:[
          ['Formula','Season buyers whose Qurbani order was their <span class="var">first-ever order</span> with us — nothing placed before it'],
          ['Converted','<span class="var">'+rsS(gm.new.regular)+'</span> of them ('+gm.new.pct+'%) have since received a <span class="var">regular (non-Qurbani) order</span>. Because Qurbani was their first contact, any regular order came after it — so this is literally conversion'],
          ['Qurbani only','<span class="var">'+rsS(gm.new.only)+'</span> have never bought regular meat. This is the win-back list — Qurbani paid to acquire them'],
          ['Counts as new','On the regular Growth tab these people count as <span class="var">new</span> on their first regular delivery (owner rule) — Qurbani is a separate business']]};
        D.g_qbold={t:'Already our customers',v:rsS(gm.existing.total),r:[
          ['Formula','Season buyers who had <span class="var">already ordered</span> something before their first Qurbani order this season'],
          ['Also regular','<span class="var">'+rsS(gm.existing.regular)+'</span> ('+gm.existing.pct+'%) also receive regular meat — Qurbani was a cross-sell to the existing base'],
          ['Qurbani only','<span class="var">'+rsS(gm.existing.only)+'</span> have ordered before but never actually received regular meat'],
          ['Regular means','Any <span class="var">delivered</span> non-Qurbani order, ever']]};
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
    salaries:{crumb:['Net profit','Salaries'],url:function(){return '/hq/drill/salaries'+qs();},
      cols:['Employee','Business unit','Amount'],map:function(r){return [esc(r.employee),esc(r.unit),rsS(r.amount)];},
      total:function(rows){var a=0;rows.forEach(function(r){a+=r.amount;});return ['Total','',rsS(a)];},
      note:'Salaries actually paid this month (Payroll screen payments + any legacy salary slips), tagged to the business unit set on each employee. The combined NF + Frozen view shows everyone; switch to a single unit to see just its salaries.'},
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
      cols:['Receivable type','Customers','Total','≤30 days','>30 days · pending'],
      map:function(r){return [esc(r.label),
        (r.customers==null?'—':rsS(r.customers)),
        rsS(r.total),
        (r.fresh==null?'—':rsS(r.fresh)),
        (r.aging==null?'—':(r.aging>0?'<span class="pillm late">'+rsS(r.aging)+'</span>':'0'))];},
      total:function(rows){var c=0,t=0,f=0,a=0;rows.forEach(function(r){c+=r.customers||0;t+=r.total||0;f+=r.fresh||0;a+=r.aging||0;});
        return ['Total',rsS(c),rsS(t),rsS(f),(a>0?'<span class="pillm late">'+rsS(a)+'</span>':'0')];},
      note:function(){return state.unit==='qb'
        ? 'This season’s delivered Qurbani orders with an unpaid balance. Older seasons are treated as closed. Click the row for the customers.'
        : 'Regular = Online Approvals “All Pending” · Shops = the Shop tab’s outstanding · Riders = cash to settle. Fresh vs pending split by invoice age (30 days). Legacy records are treated as closed; Qurbani balances live in the Qurbani view. Click a type for its customers.';},
      l2:{crumb:function(r){return r.label;},
        url:function(r){return '/hq/drill/receivables?unit='+state.unit+'&type='+encodeURIComponent(r.type);},
        cols:['Who','Inv','Amount','Oldest','Age'],
        map:function(r){return [esc(r.who),rsS(r.invoices||1),rsS(r.amount),esc(r.oldest),(r.age>30?'<span class="pillm late">'+r.age+' d</span>':r.age+' d')];}}},
    payables:{crumb:['Working capital','Payables'],url:function(){return '/hq/drill/payables';},
      cols:['Vendor','Unit','Balance'],map:function(r){return [esc(r.vendor),esc(r.unit),rsS(r.balance)];}},
    assets:{crumb:['Working capital','Fixed assets'],url:function(){return '/hq/drill/assets?unit='+state.unit;},
      cols:['Asset','Unit','Location','Bought','Book value'],
      map:function(r){return [esc(r.asset),esc(r.unit),esc(r.location),esc(r.bought),rsS(r.value)];}},
    monthassets:{crumb:['Profit & loss','Assets bought'],url:function(){return '/hq/drill/asset-purchases'+qs();},
      cols:['Asset','Unit','Bought','Entered by','Amount'],
      map:function(r){return [esc(r.asset),esc(r.unit),esc(r.bought),esc(r.who),rsS(r.amount)];},
      total:function(rows){var t=0;rows.forEach(function(r){t+=r.amount||0;});return ['Total','','','',rsS(t)];},
      note:'What was bought this month, from the asset-purchase ledger entries. This is capital spending — it is NOT in the profit figure, because the money became equipment you still own. The Fixed assets card in Working capital shows the book value of everything you own.'},
    missing_invoices:{crumb:['Working capital','Missing invoices'],url:function(){return '/hq/drill/missing-invoices';},
      cols:['Order #','Customer','Delivered','Amount','Paid'],
      map:function(r){return [esc(r.order_number),esc(r.customer),esc(r.delivered),rsS(r.amount),pill(r.paid)];}},
    prodcat:{crumb:['Products'],
      titleOverride:function(){return (drill.prodCat||'')+' — products';},
      url:function(){return '/hq/drill/products-category'+qs()+'&category='+encodeURIComponent(drill.prodCat);},
      cols:['Product','Type','Qty','Orders','Avg Rs','Revenue','vs prev'],
      map:function(r){return [esc(r.name),esc(r.type),esc(r.qty),rsS(r.orders),rsS(r.avg)+'/'+r.avg_unit,rsS(r.revenue),
        (r.prev==null?'<span class="delta flat">new</span>':deltaChip(r.revenue,r.prev))];},
      total:function(rows){var rev=0,o=0;rows.forEach(function(r){rev+=r.revenue;o+=r.orders;});
        return [rows.length+' products','','',rsS(o),'',rsS(rev),''];},
      l2:{crumb:function(r){return r.name;},
        url:function(r){return '/hq/drill/product-daily'+qs()+'&product_id='+r.pid;},
        cols:['Day','Qty','Orders','Revenue'],
        map:function(r){return [esc(r.day),esc(r.qty),rsS(r.orders),rsS(r.rev)];}}},
    proddaily:{crumb:['Products','Daily'],
      titleOverride:function(){return (drill.prodName||'Product')+' — days';},
      url:function(){return '/hq/drill/product-daily'+qs()+'&product_id='+drill.pid;},
      cols:['Day','Qty','Orders','Revenue'],
      map:function(r){return [esc(r.day),esc(r.qty),rsS(r.orders),rsS(r.rev)];},
      total:function(rows){var rev=0,o=0;rows.forEach(function(r){rev+=r.rev;o+=r.orders;});
        return ['Total','',rsS(o),rsS(rev)];}},
    recency:{crumb:['Recency'],crumbPrefix:function(){return 'Growth · whole regular base';},
      titleOverride:function(){return (drill.bandLabel||'')+' — customers';},
      url:function(){return '/hq/drill/recency?band='+encodeURIComponent(drill.band);},
      cols:['Customer','Phone','Last order','Orders','Lifetime'],
      map:function(r){return [esc(r.customer),esc(r.phone),esc(r.last)+' <small style="color:var(--faint)">('+r.days+'d)</small>',rsS(r.orders),rsS(r.spent)];},
      total:function(rows){var o=0,sp=0;rows.forEach(function(r){o+=r.orders;sp+=r.spent;});return [rows.length+' customers','','',rsS(o),rsS(sp)];}}
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
  window.hqDrillRow=function(idx){ var d=DRILL[drill.key]; if(!d.l2)return; var row=drill.l1[idx]; if(!row||row.no_drill)return; drill.row=row; drill.level=2; renderDrill(); };

  function renderDrill(){
    var d=DRILL[drill.key]; if(!d)return;
    var prefix = d.crumbPrefix ? d.crumbPrefix() : (UNITS[state.unit].name+' · '+(data.closing?data.closing.period_label:''));
    var crumb=[prefix].concat(d.crumb);
    var l2 = drill.level===2 && d.l2;
    if(l2)crumb.push(d.l2.crumb(drill.row));
    $('hqPCrumb').innerHTML=crumb.map(function(c,i){return (i?'<span class="sep">›</span>':'')+'<span>'+esc(c)+'</span>';}).join('');
    $('hqPBack').classList.toggle('show',!!l2);
    $('hqPTitle').textContent=l2?d.l2.crumb(drill.row):(d.titleOverride?d.titleOverride():(d.crumb[d.crumb.length-1]+' — '+(data.closing?data.closing.period_label:'')));
    $('hqPBody').innerHTML='<div class="p-empty">Loading…</div>';

    if(d.local){
      var rows=d.rows();
      var lnote=(typeof d.note==='function')?d.note():d.note;
      $('hqPBody').innerHTML=tbl(d.cols,rows,false)+
        (lnote?'<div class="p-note" style="border:0;padding:12px 2px 0">'+esc(lnote)+'</div>':'');
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
      body+=list.map(function(r,i){var rc=clickable&&!r.no_drill;return '<tr'+(rc?' class="rowclick" onclick="hqDrillRow('+i+')"':'')+'>'+src.map(r).map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';}).join('');
      if(!l2&&d.total)body+='<tr class="total">'+d.total(list).map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';
      body+='</tbody></table>';
      var dnote=(!l2)?((typeof d.note==='function')?d.note():d.note):null;
      if(dnote)body+='<div class="p-note" style="border:0;padding:12px 2px 0">'+esc(dnote)+'</div>';
      else if(clickable)body+='<div class="p-note" style="border:0;padding:12px 2px 0">Click a row for the underlying entries.</div>';
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
      fetchJSON('/hq/working-capital?unit='+state.unit+(force?'&fresh=1':'')),
      // Order-split is additive — its failure must never blank the whole
      // Monthly Closing tab, so it degrades to null instead of rejecting.
      fetchJSON('/hq/order-split'+qs()+suffix).catch(function(){return null;})
    ]).then(function(res){
      data.closing=res[0]; data.trend=res[1]; data.wc=res[2]; data.split=res[3];
      renderState(); renderClosing(); renderWc(); renderTrend(); setLoading(false);
    }).catch(function(e){ setLoading(false); showErr(e.message); });
  }
  window.hqReload=hqReload;
  function afterLoad(){ if(state.tab==='growth') loadGrowth(true); if(state.tab==='products') loadProducts(true); }
  window.hqSetUnit=function(u){ state.unit=u;
    var m=$('hqBuMenu'); if(m)m.classList.remove('on'); // close the dropdown on pick
    if(u==='qb'){state.year=CUR_YEAR;}
    // Leaving the Qurbani unit with a past season selected: snap back to the
    // current month (the month rail has no pill for e.g. Jul-2025).
    else if(!MONTHS.some(function(mo){return mo.y===state.year&&mo.m===state.month;})){state.year=CUR_YEAR;state.month=CUR_MONTH;}
    renderControls(); hqReload(); afterLoad(); };
  window.hqPick=function(y,m){ state.year=y; state.month=m; renderControls(); hqReload(); afterLoad(); };
  window.hqSetTab=function(t){ state.tab=t;
    $('tabClosing').classList.toggle('on',t==='closing'); $('tabGrowth').classList.toggle('on',t==='growth');
    $('tabProducts').classList.toggle('on',t==='products');
    $('hqClosing').style.display=t==='closing'?'':'none'; $('hqGrowth').style.display=t==='growth'?'':'none';
    $('hqProducts').style.display=t==='products'?'':'none';
    if(t==='growth') loadGrowth(false);
    if(t==='products') loadProducts(false); };

  // ---------- growth ----------
  function loadGrowth(force){
    if(!force && data.growth && data.growthKey===growthKey()){ renderGrowth(); return; }
    $('hqGSales').classList.add('loading');
    fetchJSON('/hq/growth'+qs()).then(function(g){
      data.growth=g; data.growthKey=growthKey(); renderGrowth(); $('hqGSales').classList.remove('loading');
    }).catch(function(e){ $('hqGSales').classList.remove('loading'); showErr(e.message); });
  }
  function growthKey(){ return state.unit+'_'+state.year+'_'+state.month; }
  function deltaVs(cur,prev,goodUp){
    var lbl = state.unit==='qb' ? 'vs last season'
      : ((data.growth&&data.growth.is_open) ? 'vs same days last month' : 'vs last month');
    return deltaChip(cur,prev,goodUp)+' '+lbl;
  }
  // For metrics that ARE percentages (repeat rate): show the move in POINTS
  // (95.8% → 86.3% = "▼ 9.5 pts"), not the relative % of a % that deltaChip
  // would render (▼ 9.9%) — owner ruling Jul-2026.
  function deltaPtsVs(cur,prev){
    var lbl = state.unit==='qb' ? 'vs last season'
      : ((data.growth&&data.growth.is_open) ? 'vs same days last month' : 'vs last month');
    if(prev==null||prev===0)return '<span class="delta flat">new</span> '+lbl;
    var d=cur-prev;
    if(Math.abs(d)<0.05)return '<span class="delta flat">±0 pts</span> '+lbl;
    var up=d>0;
    return '<span class="delta '+(up?'up':'dn')+'">'+(up?'▲':'▼')+' '+Math.abs(d).toFixed(1)+' pts</span> '+lbl;
  }

  function renderGrowth(){
    var g=data.growth; if(!g)return;
    var isQb=g.is_season, s=g.sales;
    $('hqGrowthUnit').textContent = '· '+UNITS[state.unit].name;
    function card(def,label,val,sub){
      var iBtn='<button class="i-btn" type="button" onclick="hqDef(\''+def+'\')" aria-label="How is '+esc(label)+' calculated?">i</button>';
      return '<div class="card"><div class="k-label">'+esc(label)+iBtn+'</div><div class="k-val">'+val+'</div><div class="k-sub">'+sub+'</div></div>';
    }
    $('hqGSales').innerHTML=
      card('g_aov','Average order value',rs(s.aov),deltaVs(s.aov,s.aov_prev,true))+
      card('g_new','New customers',rsS(s.new_customers),deltaVs(s.new_customers,s.new_prev,true)+' · first-time')+
      card('g_repeat','Repeat rate',s.repeat+'%',deltaPtsVs(s.repeat,s.repeat_prev)+' · '+rsS(s.returning)+' of '+rsS(s.customers)+' had bought before');

    if(isQb){
      $('hqGRegular').style.display='none'; $('hqGSeason').style.display='';
      var e=g.engine;
      $('hqSeasonCards').innerHTML=
        card('g_season','Season buyers',rsS(e.buyers),'unique customers this season')+
        card('g_seasonnew','New to Qurbani',rsS(e.new_to_season),'first Qurbani with us')+
        card('g_returning','Returning',rsS(e.returning),'also bought last season')+
        card('g_retention','Season retention',e.retention_pct+'%',rsS(e.returning)+' of '+rsS(e.buyers)+' came back');

      // Qurbani → regular meat. Two questions per season buyer: were they new to
      // Nizami Farms or already ours, and do they also buy regular meat?
      var m=e.mix;
      if(m && m.buyers>0){
        var row=function(lbl,n,pct,good){
          return '<div class="row"><span>'+lbl+'</span><span>'+rsS(n)+
            (pct!=null?' <strong style="color:'+(good?'var(--good)':'var(--mut)')+'">'+pct+'%</strong>':'')+'</span></div>';
        };
        var mixCard=function(def,label,total,sub,rows){
          return '<div class="card"><div class="k-label">'+esc(label)+
            '<button class="i-btn" type="button" onclick="hqDef(\''+def+'\')" aria-label="How is '+esc(label)+' calculated?">i</button></div>'+
            '<div class="k-val">'+rsS(total)+'</div><div class="k-sub">'+sub+'</div>'+
            '<div class="banklist">'+rows+'</div></div>';
        };
        $('hqQbMixLede').innerHTML='Of <strong>'+rsS(m.buyers)+'</strong> Qurbani buyers this season, <strong>'+
          rsS(m.new.total)+'</strong> had never bought from us before and <strong>'+rsS(m.existing.total)+
          '</strong> were already our customers. '+
          (m.new.total>0
            ? 'Only <strong>'+rsS(m.new.regular)+'</strong> of the new ones ('+m.new.pct+'%) have bought regular meat since — the other <strong>'+
              rsS(m.new.only)+'</strong> are a win-back list.'
            : '');
        $('hqQbMix').innerHTML=
          mixCard('g_qbnew','New to Nizami Farms',m.new.total,'Qurbani was their first-ever order',
            row('Converted — also buy regular meat',m.new.regular,m.new.pct,true)+
            row('Qurbani only — never bought meat',m.new.only,null,false))+
          mixCard('g_qbold','Already our customers',m.existing.total,'had ordered before this Qurbani',
            row('Also buy regular meat',m.existing.regular,m.existing.pct,true)+
            row('Qurbani only',m.existing.only,null,false));
      }else{
        $('hqQbMixLede').innerHTML=''; $('hqQbMix').innerHTML='';
      }
      return;
    }
    $('hqGRegular').style.display=''; $('hqGSeason').style.display='none';

    // active tiles (nested — point in time, no delta)
    var e=g.engine, hex=UNITS[state.unit].hex;
    $('hqGActives').innerHTML=
      card('g_a30','Active · 30 days',rsS(e.active_30),'received an order in the last 30 days')+
      card('g_a60','Active · 60 days',rsS(e.active_60),'received an order in the last 60 days')+
      card('g_a90','Active · 90 days',rsS(e.active_90),'received an order in the last 90 days');

    // recency ladder
    var bands=e.bands||[], bmax=Math.max.apply(null,bands.map(function(b){return b.count;}).concat([1]));
    var btot=bands.reduce(function(a,b){return a+b.count;},0)||1;
    $('hqLadder').innerHTML=bands.map(function(b){
      var color=b.winback?'#f59e0b':hex;
      return '<div class="lrow click'+(b.winback?' wb':'')+'" tabindex="0" role="button" onclick="hqRecency(\''+b.key+'\',\''+esc(b.label)+'\')" onkeydown="if(event.key===\'Enter\')hqRecency(\''+b.key+'\',\''+esc(b.label)+'\')">'+
        '<span class="ln">'+esc(b.label)+(b.winback?' <span class="wbtag">WIN-BACK</span>':'')+'</span>'+
        '<div><div class="lb" style="width:'+Math.max(b.count/bmax*100,2)+'%;background:'+color+(b.winback?'':';opacity:.82')+'"></div></div>'+
        '<span class="lv">'+rsS(b.count)+' <small>· '+(b.count/btot*100).toFixed(0)+'%</small></span></div>';
    }).join('');
    $('hqLadderNote').innerHTML='The <strong>61–90</strong> band is your win-back window — still warm, one nudge from lapsing. Click it for the list (name, phone, lifetime value) to run a WhatsApp campaign.';

    // cohort heatmap (single-hue green ramp by %)
    var months=(g.cohort&&g.cohort.months)||[];
    function cell(p){
      if(p===null||p===undefined) return '<span class="cempty">—</span>';
      var a=0.10+Math.min(p/55,1)*0.62; // light→dark green by %
      return '<span class="cell" style="background:rgba(5,150,105,'+a.toFixed(2)+');color:'+(p>=32?'#fff':'#065f46')+'">'+p+'%</span>';
    }
    var html='<table class="coh"><thead><tr><th>First ordered</th><th>New</th><th>M+1</th><th>M+2</th><th>M+3</th></tr></thead><tbody>';
    html+=months.map(function(m){
      return '<tr><td>'+esc(m.label)+'</td><td class="csize">'+rsS(m.size)+'</td>'+
        m.cells.map(function(p){return '<td>'+cell(p)+'</td>';}).join('')+'</tr>';
    }).join('');
    html+='</tbody></table>';
    $('hqCohort').innerHTML=html;
  }

  window.hqRecency=function(band,label){
    drill={key:'recency',level:1,l1:null,row:null,band:band,bandLabel:label};
    $('hqDrillScrim').classList.add('on'); $('hqPanel').classList.add('on');
    renderDrill();
  };

  // ---------- products ----------
  function loadProducts(force){
    if(!force && data.products && data.productsKey===growthKey()){ renderProducts(); return; }
    $('hqPCats').classList.add('loading');
    fetchJSON('/hq/products'+qs()).then(function(p){
      data.products=p; data.productsKey=growthKey(); renderProducts(); $('hqPCats').classList.remove('loading');
    }).catch(function(e){ $('hqPCats').classList.remove('loading'); showErr(e.message); });
  }
  window.hqProdCat=function(cat){
    drill={key:'prodcat',level:1,l1:null,row:null,prodCat:cat};
    $('hqDrillScrim').classList.add('on'); $('hqPanel').classList.add('on');
    renderDrill();
  };
  window.hqProdDaily=function(pid,name){
    drill={key:'proddaily',level:1,l1:null,row:null,pid:pid,prodName:name};
    $('hqDrillScrim').classList.add('on'); $('hqPanel').classList.add('on');
    renderDrill();
  };
  function moverRow(m,up){
    return '<div class="row"><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(m.name)+'</span>'+
      '<span style="white-space:nowrap"><span class="delta '+(up?'up':'dn')+'">'+(up?'▲':'▼')+' '+rsS(Math.abs(m.delta))+'</span></span></div>';
  }
  function renderProducts(){
    var p=data.products; if(!p)return;
    if(p.season){
      $('hqPQuality').style.display='none';
      $('hqPCats').innerHTML='<div class="card" style="grid-column:1/-1"><div class="k-sub">Products covers the regular business (Qurbani is excluded here — it has its own view under the Qurbani unit). Pick Nizami Farms, Khaas or NF + Frozen above.</div></div>';
      $('hqPTop').innerHTML=''; $('hqPMovers').innerHTML=''; $('hqPLife').innerHTML='';
      return;
    }
    // data-quality chip for months before the Mar-2026 catalog rebuild
    var q=p.quality||{};
    if((q.unlinked_pct||0)>2){
      $('hqPQuality').style.display='';
      $('hqPQuality').innerHTML='⚠ <strong>'+q.unlinked_pct+'%</strong> of this month’s revenue (Rs '+rsS(q.unlinked_rs)+') is on <strong>old-catalog items</strong> that no longer link to a product — shown as “Old catalog · unlinked”. Category data is complete from <strong>March 2026</strong> onward.';
    }else{ $('hqPQuality').style.display='none'; }

    $('hqPCats').innerHTML=(p.categories||[]).map(function(c){
      return '<div class="card click" tabindex="0" role="button" onclick="hqProdCat(\''+esc(c.label).replace(/'/g,"\\'")+'\')" '+
        'onkeydown="if(event.key===\'Enter\')hqProdCat(\''+esc(c.label).replace(/'/g,"\\'")+'\')">'+
        '<div class="k-label">'+esc(c.label)+'<button class="i-btn" type="button" onclick="event.stopPropagation();hqDef(\'p_cat\')" aria-label="How are categories calculated?">i</button><span class="drill">›</span></div>'+
        '<div class="k-val">'+rs(c.revenue)+'</div>'+
        '<div class="k-sub">'+deltaChip(c.revenue,c.prev)+' · '+c.share+'% of month</div>'+
        '<div class="banklist">'+
          '<div class="row"><span>Quantity</span><span>'+(c.kg>0?rsS(Math.round(c.kg))+' kg'+(c.pcs>0?' + '+rsS(c.pcs)+' pc':''):rsS(c.pcs)+' pc')+'</span></div>'+
          '<div class="row"><span>In '+rsS(c.orders)+' orders</span><span>'+c.penetration+'% of all</span></div>'+
        '</div></div>';
    }).join('');

    var t=p.top||[];
    $('hqPTop').innerHTML=!t.length?'<div class="p-empty">Nothing sold in this window.</div>':
      '<table class="dt"><thead><tr><th>Product</th><th>Category</th><th>Qty</th><th>Orders</th><th>Avg Rs</th><th>Revenue</th><th>vs prev</th></tr></thead><tbody>'+
      t.map(function(r){
        return '<tr class="rowclick" onclick="hqProdDaily('+r.pid+',\''+esc(r.name).replace(/'/g,"\\'")+'\')">'+
          '<td>'+esc(r.name)+'</td><td>'+esc(r.cat)+'</td><td>'+esc(r.qty)+'</td><td>'+rsS(r.orders)+'</td>'+
          '<td>'+rsS(r.avg)+'/'+r.avg_unit+'</td><td>'+rsS(r.revenue)+'</td>'+
          '<td>'+(r.prev==null?'<span class="delta flat">new</span>':deltaChip(r.revenue,r.prev))+'</td></tr>';
      }).join('')+'</tbody></table>';

    var mv=p.movers||{up:[],down:[]};
    $('hqPMovers').innerHTML=
      ((mv.up||[]).length?'<div class="banklist">'+mv.up.map(function(m){return moverRow(m,true);}).join('')+'</div>':'')+
      ((mv.down||[]).length?'<div class="banklist" style="margin-top:8px">'+mv.down.map(function(m){return moverRow(m,false);}).join('')+'</div>':'')+
      (!(mv.up||[]).length&&!(mv.down||[]).length?'<div class="k-sub">No product moved more than Rs 5,000 vs last month.</div>':'');

    var fp=p.fresh_products||[], dd=p.dead||{count:0,rows:[]};
    $('hqPLife').innerHTML=
      '<div class="k-sub" style="margin-bottom:4px"><strong>First sale this month</strong>'+(fp.length?'':' — none')+'</div>'+
      (fp.length?'<div class="banklist">'+fp.map(function(f){return '<div class="row"><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(f.name)+'</span><span>'+esc(f.first)+' · '+rsS(f.rev)+'</span></div>';}).join('')+'</div>':'')+
      '<div class="k-sub" style="margin:10px 0 4px"><strong>Quiet listings</strong> — '+rsS(dd.count)+' active products with no sale in 60+ days'+(dd.rows.length?' (top by lifetime sales):':'')+'</div>'+
      (dd.rows.length?'<div class="banklist">'+dd.rows.map(function(d){return '<div class="row"><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(d.name)+'</span><span>last '+esc(d.last)+'</span></div>';}).join('')+'</div>':'');
  }

  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){
    if($('hqDefBox').classList.contains('on'))hqCloseDef();
    else if($('hqPanel').classList.contains('on'))hqCloseDrill(); } });

  renderControls();
  hqReload();
})();
</script>
@endsection
