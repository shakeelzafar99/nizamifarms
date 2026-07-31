{{-- Ledger Hub styles. Scoped under .nfhub so nothing leaks into the rest of the app.
     Theme-aware via the app's html[data-kt-theme="dark"]. Pushed to the layout's custom_css stack
     (NOT 'styles' — that stack does not exist in layouts.app). --}}
@push('custom_css')
<style>
  .nfhub{
    --bg:#F4F6F5; --surface:#FFFFFF; --surface2:#ECF0EE; --line:#D9E1DC; --line2:#E7ECE9;
    --ink:#18221C; --ink2:#54635B; --ink3:#7E8C84;
    --accent:#0E7A52; --accent-ink:#FFFFFF; --accent-soft:#E1F0E9; --accent-line:#B9DCCC;
    --in:#177A3D; --in-soft:#E3F2E8;
    --out:#B0392F; --out-soft:#F8E9E7;
    --owe:#9A5C0B; --owe-soft:#FAEFDB;
    --info:#28609F; --info-soft:#E4EDF7;
    --shadow:0 1px 2px rgba(24,34,28,.06),0 4px 14px rgba(24,34,28,.06);
    --shadow-lg:0 8px 32px rgba(24,34,28,.18);
    --radius:10px;
    color:var(--ink);
    font-family:Inter,"Segoe UI",system-ui,-apple-system,sans-serif;
    font-size:14px; line-height:1.5;
  }
  html[data-kt-theme="dark"] .nfhub{
    --bg:#0E1411; --surface:#151C18; --surface2:#1C2420; --line:#2A342E; --line2:#232C27;
    --ink:#E5ECE7; --ink2:#9DAFA5; --ink3:#6F7F77;
    --accent:#3FC493; --accent-ink:#07281A; --accent-soft:#143529; --accent-line:#1F4A37;
    --in:#52C97F; --in-soft:#12331F;
    --out:#EF8B7D; --out-soft:#3A1E1A;
    --owe:#E0A44F; --owe-soft:#382A12;
    --info:#82B2E5; --info-soft:#17293D;
    --shadow:0 1px 2px rgba(0,0,0,.35),0 4px 14px rgba(0,0,0,.3);
    --shadow-lg:0 8px 32px rgba(0,0,0,.5);
  }
  .nfhub, .nfhub *{box-sizing:border-box}
  .nfhub .num{font-variant-numeric:tabular-nums}
  .nfhub .mono{font-family:"Cascadia Mono",Consolas,ui-monospace,monospace;font-size:.86em}
  .nfhub button{font:inherit;color:inherit}

  .nfhub{padding:18px 22px 60px;max-width:1180px}
  @media (max-width:820px){ .nfhub{padding:14px 12px 60px} }

  /* header */
  .nfhub .hub-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}
  .nfhub .hub-title{display:flex;align-items:center;gap:10px}
  .nfhub .hub-title h1{font-size:22px;font-weight:700;margin:0;letter-spacing:-.01em}
  .nfhub .beta{font-size:10px;letter-spacing:.1em;text-transform:uppercase;font-weight:800;
    background:var(--accent-soft);color:var(--accent);padding:2px 8px;border-radius:99px}
  .nfhub .hub-sub{color:var(--ink2);font-size:13px;margin:2px 0 0}
  .nfhub .hub-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .nfhub .btn{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:8px;
    font-weight:600;font-size:13px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);
    text-decoration:none;transition:border-color .12s,background .12s;cursor:pointer}
  .nfhub .btn:hover{border-color:var(--ink3);color:var(--ink)}
  .nfhub .btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
  .nfhub .btn.primary:hover{filter:brightness(1.06);color:var(--accent-ink)}
  .nfhub .btn.ghost{background:transparent}

  /* tab bar */
  .nfhub .hub-tabs{display:flex;gap:2px;background:var(--surface2);border-radius:10px;padding:3px;
    margin-bottom:14px;overflow-x:auto}
  .nfhub .hub-tab{display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;
    font-size:13.5px;font-weight:600;color:var(--ink2);text-decoration:none;white-space:nowrap;transition:all .12s}
  .nfhub .hub-tab:hover{color:var(--ink)}
  .nfhub .hub-tab.on{background:var(--surface);color:var(--ink);box-shadow:var(--shadow)}
  .nfhub .hub-tab .tb{margin-left:2px;background:var(--owe-soft);color:var(--owe);font-size:11px;
    font-weight:700;padding:0 6px;border-radius:99px}

  /* scope bar */
  .nfhub .scope-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--surface);
    border:1px solid var(--line);border-radius:var(--radius);padding:8px 12px;margin-bottom:16px;box-shadow:var(--shadow)}
  .nfhub .scope-bar .sb-label{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);font-weight:700}
  .nfhub .scope-seg{display:inline-flex;background:var(--surface2);border-radius:9px;padding:3px;gap:2px}
  .nfhub .scope-seg a{padding:5px 13px;border-radius:6px;font-size:12.5px;font-weight:600;color:var(--ink2);
    display:inline-flex;align-items:center;gap:7px;transition:background .12s,color .12s;text-decoration:none}
  .nfhub .scope-seg a:hover{color:var(--ink)}
  .nfhub .scope-seg a .sdot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
  .nfhub .scope-seg a.on{background:var(--surface);color:var(--ink);box-shadow:var(--shadow)}
  .nfhub .scope-note{font-size:11.5px;color:var(--ink3);margin-left:auto;text-align:right}

  /* pending strip */
  .nfhub .pending-strip{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--owe-soft);
    border:1px solid color-mix(in srgb,var(--owe) 25%,transparent);border-radius:var(--radius);padding:10px 14px;margin-bottom:16px}
  .nfhub .pending-strip .pdot{width:8px;height:8px;border-radius:50%;background:var(--owe);flex:0 0 auto}
  .nfhub .pending-strip b{color:var(--owe)}
  .nfhub .pending-strip .spacer{flex:1}

  /* tiles */
  .nfhub .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
  .nfhub .tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:13px 15px;text-align:left;box-shadow:var(--shadow);width:100%;display:block;text-decoration:none;color:inherit}
  .nfhub .tile .t-label{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);font-weight:700}
  .nfhub .tile .t-value{font-size:20px;font-weight:700;margin:4px 0 6px}
  .nfhub .tile .t-sub{font-size:11.5px;color:var(--ink2);display:flex;flex-direction:column;gap:2px}
  .nfhub .tile .t-sub .g{color:var(--in)} .nfhub .tile .t-sub .r{color:var(--out)} .nfhub .tile .t-sub .o{color:var(--owe)}
  .nfhub .tile .t-sub .row{display:flex;justify-content:space-between;gap:8px}

  /* note card */
  .nfhub .note-card{background:var(--info-soft);border:1px solid color-mix(in srgb,var(--info) 22%,transparent);
    color:var(--ink2);border-radius:var(--radius);padding:11px 15px;margin-bottom:16px;font-size:12.5px}
  .nfhub .note-card b{color:var(--ink)}

  /* filter bar */
  .nfhub .filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    padding:10px 12px;margin-bottom:12px}
  .nfhub .filter-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .nfhub .f-field{display:flex;flex-direction:column;gap:3px}
  .nfhub .f-field label{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);font-weight:700}
  .nfhub .f-field input,.nfhub .f-field select{border:1px solid var(--line);background:var(--surface);color:var(--ink);
    border-radius:7px;padding:5px 9px;font:inherit;font-size:12.5px;min-width:120px}
  .nfhub .f-field input:focus,.nfhub .f-field select:focus{outline:none;border-color:var(--accent)}
  .nfhub .f-field.grow{flex:1;min-width:150px}
  .nfhub .f-field.grow input{min-width:0;width:100%}

  /* card + table */
  .nfhub .card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
  .nfhub .card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 16px;border-bottom:1px solid var(--line2);flex-wrap:wrap}
  .nfhub .card-head h3{margin:0;font-size:14px;font-weight:700}
  .nfhub .card-head .meta{font-size:12px;color:var(--ink3)}
  .nfhub .table-wrap{overflow-x:auto}
  .nfhub table{border-collapse:collapse;width:100%;min-width:720px;margin:0}
  .nfhub thead th{font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;color:var(--ink3);font-weight:700;
    text-align:left;padding:8px 14px;border-bottom:1px solid var(--line2);background:var(--surface);white-space:nowrap}
  .nfhub th.r,.nfhub td.r{text-align:right}
  .nfhub tbody td{padding:9px 14px;border-bottom:1px solid var(--line2);vertical-align:middle;background:var(--surface)}
  .nfhub tbody tr:last-child td{border-bottom:none}
  .nfhub tbody tr.t-row{cursor:pointer}
  .nfhub tbody tr.t-row:hover td{background:var(--surface2)}
  .nfhub .cell-date{white-space:nowrap;color:var(--ink2);font-size:12.5px}
  .nfhub .cell-date b{color:var(--ink);font-weight:600}
  .nfhub .type-chip{display:inline-block;padding:2px 9px;border-radius:6px;background:var(--surface2);
    color:var(--ink2);font-size:11.5px;font-weight:600;white-space:nowrap}
  .nfhub .flow{font-size:12.5px;color:var(--ink2);white-space:nowrap}
  .nfhub .flow b{color:var(--ink);font-weight:600}
  .nfhub .flow .arr{color:var(--ink3);padding:0 3px}
  .nfhub .bank-tag{display:inline-block;font-size:10px;font-weight:700;padding:1px 6px;border-radius:5px;
    background:var(--info-soft);color:var(--info);margin-left:6px;vertical-align:1px}
  .nfhub .desc{color:var(--ink2);font-size:12.5px;max-width:280px}
  .nfhub .desc b{color:var(--ink)}
  .nfhub .amt{font-weight:700;white-space:nowrap}
  .nfhub .amt.in{color:var(--in)} .nfhub .amt.out{color:var(--out)} .nfhub .amt.owe{color:var(--owe)} .nfhub .amt.neutral{color:var(--ink2)}
  .nfhub .status{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
  .nfhub .status::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
  .nfhub .status.l1{background:var(--owe-soft);color:var(--owe)}
  .nfhub .status.l2{background:var(--info-soft);color:var(--info)}
  .nfhub .status.ok{background:var(--in-soft);color:var(--in)}
  .nfhub .status.rej{background:var(--out-soft);color:var(--out)}
  .nfhub .chev{color:var(--ink3);font-size:15px;text-align:right}
  .nfhub .empty{padding:40px 16px;text-align:center;color:var(--ink3);font-size:13px}
  .nfhub .hub-pager{padding:12px 16px}
  .nfhub .hub-pager nav div p{color:var(--ink3)!important}

  /* drawer */
  .nfhub .scrim{position:fixed;inset:0;background:rgba(10,16,12,.45);opacity:0;pointer-events:none;transition:opacity .18s;z-index:1040}
  .nfhub .scrim.on{opacity:1;pointer-events:auto}
  .nfhub .drawer{position:fixed;top:0;right:-460px;width:440px;max-width:94vw;height:100vh;z-index:1050;
    background:var(--surface);border-left:1px solid var(--line);box-shadow:var(--shadow-lg);
    transition:right .22s ease;display:flex;flex-direction:column}
  .nfhub .drawer.on{right:0}
  .nfhub .drawer-head{padding:16px 20px;border-bottom:1px solid var(--line2);display:flex;align-items:flex-start;gap:10px}
  .nfhub .drawer-head h3{margin:0;font-size:15px;font-weight:700}
  .nfhub .drawer-head .d-sub{font-size:12px;color:var(--ink3)}
  .nfhub .drawer-close{margin-left:auto;font-size:18px;color:var(--ink3);padding:2px 8px;border-radius:6px;background:none;border:none;cursor:pointer}
  .nfhub .drawer-close:hover{background:var(--surface2);color:var(--ink)}
  .nfhub .drawer-body{padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:16px}
  .nfhub .d-amount{text-align:center;padding:14px;background:var(--surface2);border-radius:var(--radius)}
  .nfhub .d-amount .d-big{font-size:26px;font-weight:700}
  .nfhub .d-amount .d-mode{font-size:12px;color:var(--ink2);margin-top:2px}
  .nfhub .d-flow{display:flex;align-items:stretch;gap:8px}
  .nfhub .d-acct{flex:1;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font-size:12px;color:var(--ink3)}
  .nfhub .d-acct b{display:block;color:var(--ink);font-size:13px;font-weight:600;margin-top:1px}
  .nfhub .d-acct.src{border-left:3px solid var(--out)} .nfhub .d-acct.dst{border-left:3px solid var(--in)}
  .nfhub .d-arrow{align-self:center;color:var(--ink3);font-size:16px}
  .nfhub .d-section h4{margin:0 0 8px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3)}
  .nfhub .d-kv{display:grid;grid-template-columns:auto 1fr;gap:5px 16px;font-size:12.5px}
  .nfhub .d-kv dt{color:var(--ink3);margin:0}
  .nfhub .d-kv dd{margin:0;color:var(--ink);font-weight:500;text-align:right}
  .nfhub .drawer-foot{padding:14px 20px;border-top:1px solid var(--line2);display:flex;flex-direction:column;gap:10px}
  .nfhub .drawer-foot textarea{width:100%;border:1px solid var(--line);border-radius:8px;background:var(--surface);
    color:var(--ink);padding:8px 10px;font:inherit;font-size:12.5px;resize:vertical;min-height:38px}
  .nfhub .foot-btns{display:flex;gap:8px}
  .nfhub .foot-btns .btn{flex:1;justify-content:center}
  .nfhub .btn.danger{color:var(--out);border-color:color-mix(in srgb,var(--out) 40%,transparent)}
  .nfhub .btn.danger:hover{background:var(--out-soft);color:var(--out)}
  .nfhub .d-hint{font-size:11.5px;color:var(--ink3);text-align:center}

  /* section labels + back link */
  .nfhub .sec-label{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);font-weight:700;margin:18px 0 8px}
  .nfhub .sec-label:first-of-type{margin-top:4px}
  .nfhub .sec-label .sl-meta{color:var(--ink3);font-weight:500;letter-spacing:0;text-transform:none;font-size:12px;margin-left:8px}
  .nfhub .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--ink2);font-size:13px;font-weight:600;margin-bottom:10px;text-decoration:none}
  .nfhub .back-link:hover{color:var(--accent)}
  .nfhub .acct-card .t-value{font-size:19px}
  .nfhub .acct-card .t-cat{font-size:11px;color:var(--ink3);text-transform:capitalize}

  /* riders board */
  .nfhub .rider-name{font-weight:600;display:flex;align-items:center;gap:9px}
  .nfhub .avatar{width:28px;height:28px;border-radius:50%;background:var(--accent-soft);color:var(--accent);
    display:grid;place-items:center;font-size:11px;font-weight:700;flex:0 0 auto}
  .nfhub .rstate{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;white-space:nowrap}
  .nfhub .rstate.clean{background:var(--in-soft);color:var(--in)}
  .nfhub .rstate.holding{background:var(--surface2);color:var(--ink2)}
  .nfhub .rstate.settle{background:var(--owe-soft);color:var(--owe)}
  .nfhub .rstate.flag{background:var(--out-soft);color:var(--out)}
  .nfhub .row-actions{display:flex;gap:6px;justify-content:flex-end}
  .nfhub .mini-btn{font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:7px;border:1px solid var(--line);
    color:var(--ink2);background:var(--surface);transition:all .12s;white-space:nowrap;text-decoration:none;display:inline-block}
  .nfhub .mini-btn:hover{border-color:var(--accent);color:var(--accent)}
  .nfhub .mini-btn.solid{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
  .nfhub .mini-btn.solid:hover{color:var(--accent-ink);filter:brightness(1.06)}
  .nfhub .mini-btn.on{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}

  /* balance header (account detail) */
  .nfhub .bal-head{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:18px 20px;margin-bottom:14px;display:flex;gap:26px;align-items:center;flex-wrap:wrap}
  .nfhub .bal-main .b-label{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);font-weight:700}
  .nfhub .num-lg{font-size:30px;font-weight:700;line-height:1.15}
  .nfhub .bal-main .b-note{font-size:12px;color:var(--ink3);margin-top:2px}
  .nfhub .bal-chips{display:flex;gap:8px;flex-wrap:wrap}
  .nfhub .stat-chip{background:var(--surface2);border-radius:8px;padding:7px 12px;font-size:12px;color:var(--ink2)}
  .nfhub .stat-chip b{display:block;color:var(--ink);font-size:14px;font-weight:700}
  .nfhub .bal-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}

  /* day groups */
  .nfhub .day-group{border-bottom:1px solid var(--line2)}
  .nfhub .day-group:last-child{border-bottom:none}
  .nfhub .day-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--surface2);padding:7px 16px;font-size:12px;color:var(--ink2)}
  .nfhub .day-head b{color:var(--ink);font-size:12.5px}
  .nfhub .day-net{margin-left:auto;font-weight:700;font-size:11.5px;padding:2px 10px;border-radius:99px}
  .nfhub .day-net.balanced{background:var(--in-soft);color:var(--in)}
  .nfhub .day-net.holding{background:var(--owe-soft);color:var(--owe)}
  .nfhub .day-net.short{background:var(--out-soft);color:var(--out)}
  .nfhub .day-net.historic{background:var(--surface);color:var(--ink3);font-weight:600;border:1px dashed var(--line)}

  /* clickable stat chip */
  .nfhub .stat-chip.tap{cursor:pointer;transition:border-color .12s,background .12s;border:1px solid transparent}
  .nfhub .stat-chip.tap:hover{background:var(--accent-soft);border-color:var(--accent-line)}
  .nfhub .stat-chip.tap::after{content:" ›";color:var(--accent)}
  .nfhub .num-lg.tap{cursor:pointer}
  .nfhub .num-lg.tap:hover{text-decoration:underline dotted;text-underline-offset:4px}

  /* modal (shared: invoice breakdown, transfer, settle) */
  .hubmodal{position:fixed;inset:0;display:none;place-items:center;z-index:1060;background:rgba(10,16,12,.5);padding:20px;
    font-family:Inter,"Segoe UI",system-ui,sans-serif}
  .hubmodal.on{display:grid}
  html[data-kt-theme="dark"] .hubmodal{background:rgba(0,0,0,.6)}
  .hubmodal-box{background:var(--surface);color:var(--ink);border-radius:14px;box-shadow:var(--shadow-lg);
    max-width:560px;width:100%;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;border:1px solid var(--line)}
  .hubmodal-box.wide{max-width:640px}
  .hubmodal-head{padding:16px 20px;border-bottom:1px solid var(--line2);display:flex;align-items:flex-start;gap:10px}
  .hubmodal-head h3{margin:0;font-size:16px;font-weight:700}
  .hubmodal-head .hm-sub{font-size:12px;color:var(--ink3);margin-top:1px}
  .hubmodal-x{margin-left:auto;font-size:18px;color:var(--ink3);padding:2px 8px;border-radius:6px;background:none;border:none;cursor:pointer}
  .hubmodal-x:hover{background:var(--surface2);color:var(--ink)}
  .hubmodal-body{padding:16px 20px;overflow-y:auto;flex:1}
  .hubmodal-foot{padding:14px 20px;border-top:1px solid var(--line2);display:flex;gap:8px;justify-content:flex-end}
  .hubmodal .fld{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
  .hubmodal .fld label{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--ink3);font-weight:700}
  .hubmodal .fld input,.hubmodal .fld select,.hubmodal .fld textarea{border:1px solid var(--line);background:var(--surface);
    color:var(--ink);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;width:100%}
  .hubmodal .fld input:focus,.hubmodal .fld select:focus,.hubmodal .fld textarea:focus{outline:none;border-color:var(--accent)}
  .hubmodal .fld .hint{font-size:11px;color:var(--ink3)}
  .hubmodal .fld-row{display:flex;gap:10px}
  .hubmodal .fld-row .fld{flex:1}
  .hubmodal .bankchips{display:flex;gap:6px;flex-wrap:wrap}
  .hubmodal .bankchip{padding:5px 11px;border-radius:8px;border:1px solid var(--line);background:var(--surface);
    font-size:12px;font-weight:600;color:var(--ink2);cursor:pointer}
  .hubmodal .bankchip.on{border-color:var(--accent);background:var(--accent-soft);color:var(--accent)}
  .hubmodal .m-err{background:var(--out-soft);color:var(--out);border-radius:8px;padding:8px 12px;font-size:12.5px;font-weight:600;margin-bottom:12px;display:none}
  .hubmodal .m-err.on{display:block}
  /* invoice list (breakdown + settle) */
  .inv-list{display:flex;flex-direction:column;gap:0}
  .inv-row{display:flex;align-items:center;gap:10px;padding:10px 2px;border-bottom:1px solid var(--line2);font-size:13px}
  .inv-row:last-child{border-bottom:none}
  .inv-row .iv-main{flex:1;min-width:0}
  .inv-row .iv-main b{color:var(--ink);font-weight:600}
  .inv-row .iv-sub{font-size:11.5px;color:var(--ink3)}
  .inv-row .iv-amt{font-weight:700;color:var(--owe);white-space:nowrap;font-variant-numeric:tabular-nums}
  .inv-row .iv-chk{width:16px;height:16px;flex:0 0 auto}
  .inv-total{display:flex;justify-content:space-between;padding:11px 2px 2px;font-weight:700;border-top:2px solid var(--line);margin-top:6px}
  .inv-total .num{font-variant-numeric:tabular-nums}

  /* toast */
  .hubtoast{position:fixed;bottom:24px;left:50%;transform:translate(-50%,12px);z-index:1080;
    background:var(--ink);color:var(--bg);padding:9px 18px;border-radius:99px;font-size:13px;font-weight:600;
    opacity:0;pointer-events:none;transition:all .2s;box-shadow:var(--shadow-lg);font-family:Inter,system-ui,sans-serif}
  .hubtoast.on{opacity:1;transform:translate(-50%,0)}

  /* banks */
  .nfhub .recon-banner{display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-radius:var(--radius);
    padding:11px 16px;margin-bottom:14px;font-size:13px;font-weight:600;border:1px solid}
  .nfhub .recon-banner.green{background:var(--in-soft);color:var(--in);border-color:color-mix(in srgb,var(--in) 25%,transparent)}
  .nfhub .recon-banner.amber{background:var(--owe-soft);color:var(--owe);border-color:color-mix(in srgb,var(--owe) 25%,transparent)}
  .nfhub .recon-banner.red{background:var(--out-soft);color:var(--out);border-color:color-mix(in srgb,var(--out) 30%,transparent)}
  .nfhub .recon-banner .formula{font-weight:400;color:var(--ink2);font-size:12px}
  .nfhub .pool-banner{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
  .nfhub .pool-banner .p-main .p-label{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);font-weight:700}
  .nfhub .pool-banner .p-main .p-val{font-size:22px;font-weight:700}
  .nfhub .pool-banner .p-accts{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
  .nfhub .pool-chip{background:var(--surface2);border-radius:8px;padding:6px 11px;font-size:12px;color:var(--ink2)}
  .nfhub .pool-chip b{display:block;color:var(--ink);font-weight:700}
  .nfhub .bank-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-bottom:16px}
  .nfhub .bank-card{background:var(--surface);border:1px solid var(--line);border-left-width:4px;border-radius:var(--radius);
    padding:13px 15px;text-align:left;box-shadow:var(--shadow);width:100%;cursor:pointer;transition:border-color .12s}
  .nfhub .bank-card:hover{border-color:var(--accent-line)}
  .nfhub .bank-card .b-name{font-weight:700;font-size:13.5px;display:flex;justify-content:space-between;align-items:center;gap:6px}
  .nfhub .bank-card .b-bal{font-size:19px;font-weight:700;margin-top:4px}
  .nfhub .bank-card .b-meta{font-size:11.5px;color:var(--ink3);margin-top:3px}
  .nfhub .bank-card .b-foot{display:flex;gap:6px;margin-top:9px}
  .nfhub .bank-card.untagged{border-style:dashed;border-left-style:solid;border-left-color:var(--owe)}
  .nfhub .bank-card .inactive-tag{font-size:10px;font-weight:700;color:var(--ink3);background:var(--surface2);padding:1px 6px;border-radius:5px}
  .nfhub .stmt-head{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:2px 0 12px}
  .nfhub .stmt-tot{display:flex;gap:14px;font-size:12.5px}
  .nfhub .stmt-tot .g{color:var(--in);font-weight:700} .nfhub .stmt-tot .r{color:var(--out);font-weight:700}

  /* one-time setup notice (pre-baseline) — informative, deliberately NOT alarming */
  .nfhub .setup-banner{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--surface);
    border:1px dashed var(--accent-line);border-left:4px solid var(--accent);border-radius:var(--radius);
    padding:13px 16px;margin-bottom:14px;box-shadow:var(--shadow)}
  .nfhub .setup-banner .su-ico{font-size:20px;color:var(--accent);flex:0 0 auto}
  .nfhub .setup-banner .su-text{flex:1;min-width:240px;font-size:12.5px;color:var(--ink2);line-height:1.5}
  .nfhub .setup-banner .su-text b{color:var(--ink)}
  .nfhub .ok-chip{display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;letter-spacing:.02em;
    background:var(--in-soft);color:var(--in);padding:1px 8px;border-radius:99px;margin-left:8px;vertical-align:1px;
    text-transform:none}

  /* pool split + distribution meter */
  .nfhub .p-split{display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;font-size:12px;color:var(--ink2)}
  .nfhub .p-split b{color:var(--ink);font-weight:700;margin-left:5px}
  .nfhub .p-split .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:0}
  .nfhub .p-split .dot.in{background:var(--in)} .nfhub .p-split .dot.owe{background:var(--owe)} .nfhub .p-split .dot.out{background:var(--out)}
  .nfhub .p-meter{height:6px;border-radius:99px;background:var(--owe-soft);overflow:hidden;margin-top:9px;max-width:420px}
  .nfhub .p-meter span{display:block;height:100%;background:var(--in);border-radius:99px}

  /* bank pill in the combined feed */
  .nfhub .bankpill{display:inline-block;padding:2px 9px;border-radius:6px;background:var(--surface2);color:var(--ink2);
    font-size:11.5px;font-weight:700;white-space:nowrap;text-decoration:none}
  .nfhub .bankpill:hover{color:var(--accent)}
  .nfhub .bankpill.none{background:var(--owe-soft);color:var(--owe)}

  /* pre-reset (historic) statement rows — visible, never counted */
  .nfhub tr.pre-reset td{opacity:.55;background:var(--surface2)}
  .nfhub tr.reset-row td{background:var(--accent-soft);font-weight:700}
  .nfhub tr.reset-row .type-chip{background:var(--accent);color:var(--accent-ink)}
  .nfhub .hist-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:9px 16px;background:var(--surface2);
    border-top:1px solid var(--line2);font-size:12px;color:var(--ink3)}

  /* rebalance wizard */
  .hubmodal .rb-list{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:9px;overflow:hidden}
  .hubmodal .rb-row{display:flex;align-items:center;gap:10px;padding:8px 11px;border-bottom:1px solid var(--line2)}
  .hubmodal .rb-row:last-child{border-bottom:none}
  .hubmodal .rb-row.off{opacity:.62}
  .hubmodal .rb-dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
  .hubmodal .rb-name{flex:1;min-width:0;font-size:13px;font-weight:600;color:var(--ink);display:flex;align-items:center;gap:7px;flex-wrap:wrap}
  .hubmodal .rb-cur{font-size:11px;font-weight:500;color:var(--ink3)}
  .hubmodal .rb-amt{width:140px;flex:0 0 auto;border:1px solid var(--line);background:var(--surface);color:var(--ink);
    border-radius:7px;padding:6px 9px;font:inherit;font-size:13px;text-align:right;font-variant-numeric:tabular-nums}
  .hubmodal .rb-amt:focus{outline:none;border-color:var(--accent)}
  .hubmodal .rb-foot{display:flex;gap:18px;flex-wrap:wrap;justify-content:flex-end;margin-top:11px;padding:9px 12px;
    border-radius:8px;font-size:12.5px;color:var(--ink2);background:var(--surface2)}
  .hubmodal .rb-foot b{color:var(--ink);margin-left:5px}
  .hubmodal .rb-foot.ok{background:var(--in-soft);color:var(--in)} .hubmodal .rb-foot.ok b{color:var(--in)}
  .hubmodal .rb-foot.off{background:var(--owe-soft);color:var(--owe)} .hubmodal .rb-foot.off b{color:var(--owe)}
  /* pinned copy of the summary at the top of the wizard — stays visible while the bank list scrolls */
  .hubmodal .rb-foot.rb-top{position:sticky;top:-16px;z-index:5;margin:0 0 12px;box-shadow:var(--shadow)}

  /* vendor statement: day-header quick-add buttons + bill-image chip */
  .nfhub .day-add{display:inline-flex;gap:4px;margin-left:8px}
  .nfhub .day-add .mini-btn{padding:2px 8px;font-size:11px}
  .nfhub .bill-chip{border:0;background:none;cursor:pointer;font-size:12px;padding:0 3px;vertical-align:-1px}
  .nfhub .bill-chip:hover{transform:scale(1.15)}

  /* vendor products manager */
  .hubmodal .pr-form{background:var(--surface2);border:1px solid var(--line);border-radius:10px;
    padding:12px 14px 8px;margin-bottom:14px}
  .hubmodal .pr-row{display:flex;align-items:center;gap:12px;padding:9px 4px;border-bottom:1px solid var(--line)}
  .hubmodal .pr-row:last-child{border-bottom:0}
  .hubmodal .pr-row.off{opacity:.55}
  .hubmodal .pr-name{flex:1;font-size:13px;font-weight:600;color:var(--ink)}
  .hubmodal .pr-star{color:var(--owe);margin-right:3px}
  .hubmodal .pr-rate{font-size:12.5px;font-weight:700;color:var(--ink2);white-space:nowrap}
  .hubmodal .pr-unit{font-weight:500;color:var(--ink3);font-size:11px}
  .hubmodal .pr-row .row-actions{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}

  /* vendor statement: collapsible month sections wrapping the day groups */
  .nfhub .month-block{border-top:1px solid var(--line)}
  .nfhub .month-block:first-of-type{border-top:0}
  .nfhub .month-head{display:flex;align-items:center;gap:12px;width:100%;padding:11px 16px;background:none;
    border:0;cursor:pointer;text-align:left;font:inherit;color:var(--ink2);transition:background .12s}
  .nfhub .month-head:hover{background:var(--surface2)}
  .nfhub .month-head .m-caret{color:var(--ink3);font-size:11px;transition:transform .15s;flex:0 0 auto}
  .nfhub .month-block.open > .month-head .m-caret{transform:rotate(90deg)}
  .nfhub .month-head .m-label{font-size:13.5px;color:var(--ink);font-weight:800}
  .nfhub .month-head .m-count{font-size:11px;color:var(--ink3);font-weight:600}
  .nfhub .month-head .m-sums{font-size:11.5px;color:var(--ink3);margin-left:auto}
  .nfhub .month-head .m-closing{font-size:11px;color:var(--ink3);white-space:nowrap}
  .nfhub .month-head .m-closing b{font-size:12.5px;margin-left:4px}
  .nfhub .month-body{display:none}
  .nfhub .month-block.open > .month-body{display:block}
  .nfhub .month-loading{padding:14px 16px;font-size:12px;color:var(--ink3)}
  @media (max-width:760px){
    .nfhub .month-head{flex-wrap:wrap;gap:6px 10px}
    .nfhub .month-head .m-sums{margin-left:0;width:100%}
  }

  /* statement: compact leading Time column (clock when same-day; "↩ <date>" when backdated) */
  .nfhub .col-time{width:74px;white-space:nowrap}
  .nfhub .row-time{font-size:10.5px;color:var(--ink3);white-space:nowrap;font-weight:600;
    font-variant-numeric:tabular-nums}
  .nfhub .row-time.late{font-style:italic}

  /* drawer: balance before → after for a statement row */
  .drawer .d-bal{display:flex;align-items:center;gap:12px}
  .drawer .d-bal > div{flex:1;display:flex;flex-direction:column;gap:2px;background:var(--surface2);
    border:1px solid var(--line);border-radius:9px;padding:8px 10px}
  .drawer .d-bal span{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink3);font-weight:700}
  .drawer .d-bal b{font-size:14px;color:var(--ink);font-weight:800}
  .drawer .d-bal .d-bal-arrow{flex:0 0 auto;background:none;border:0;padding:0;color:var(--ink3);font-size:14px}

  /* bank-to-bank transfer: before/after preview so the effect is obvious before committing */
  .hubmodal .bx-preview{background:var(--surface2);border:1px solid var(--line);border-radius:10px;
    padding:10px 12px;margin:2px 0 12px}
  .hubmodal .bx-line{display:flex;justify-content:space-between;align-items:center;gap:12px;
    font-size:12.5px;color:var(--ink2);padding:2px 0}
  .hubmodal .bx-line b{color:var(--ink);font-weight:700}
  .hubmodal .bx-line .neg{color:var(--out)}
  .hubmodal .bx-note{font-size:11px;color:var(--ink3);margin-top:6px;padding-top:6px;border-top:1px dashed var(--line)}
  .hubmodal .btn.primary:disabled{opacity:.45;cursor:not-allowed;filter:none}
  .hubmodal .bankchip.unsure{border-style:dashed;color:var(--owe);border-color:color-mix(in srgb,var(--owe) 40%,transparent)}
  .hubmodal .bankchip.unsure.on{background:var(--owe-soft);border-color:var(--owe);color:var(--owe)}
  .hubmodal .inactive-tag{font-size:10px;font-weight:700;color:var(--ink3);background:var(--surface2);padding:1px 6px;border-radius:5px}

  /* placeholder */
  .nfhub .ph{background:var(--surface);border:1px dashed var(--line);border-radius:var(--radius);
    padding:40px 24px;text-align:center;box-shadow:var(--shadow)}
  .nfhub .ph h2{margin:0 0 6px;font-size:18px}
  .nfhub .ph p{color:var(--ink2);font-size:13.5px;margin:0 auto 16px;max-width:520px}
</style>
@endpush
