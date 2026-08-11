{{-- Ledger Hub styles. Scoped under .nfhub so nothing leaks into the rest of the app.
     Theme-aware via the app's html[data-kt-theme="dark"]. Pushed to the layout's custom_css stack
     (NOT 'styles' — that stack does not exist in layouts.app). --}}
@push('custom_css')
<style>
  .nfhub{
    --bg:#F4F6F5; --surface:#FFFFFF; --surface2:#ECF0EE; --line:#D9E1DC; --line2:#E7ECE9;
    --ink:#18221C; --ink2:#54635B; --ink3:#7E8C84;
    --accent:#0E7A52; --accent-ink:#FFFFFF; --accent-soft:#E1F0E9; --accent-line:#B9DCCC;
    --in:#177A3D; --in-soft:#E3F2E8; --in-ink:#FFFFFF;
    --out:#B0392F; --out-soft:#F8E9E7;
    --owe:#9A5C0B; --owe-soft:#FAEFDB; --owe-ink:#FFFFFF;
    --info:#28609F; --info-soft:#E4EDF7; --info-ink:#FFFFFF;
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
    --in:#52C97F; --in-soft:#12331F; --in-ink:#07281A;
    --out:#EF8B7D; --out-soft:#3A1E1A;
    --owe:#E0A44F; --owe-soft:#382A12; --owe-ink:#2A1B05;
    --info:#82B2E5; --info-soft:#17293D; --info-ink:#0A1F33;
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

  /* ⏳ pending balance actions (account page) — the ledger rows standing between money and this
     account's balance. Amber like the pending strip, because it is the same "someone must act"
     signal, but a card rather than a strip since each row is individually actionable. */
  .nfhub .pact{background:var(--owe-soft);border:1px solid color-mix(in srgb,var(--owe) 28%,transparent);
    border-radius:var(--radius);margin-bottom:16px;overflow:hidden}
  .nfhub .pact-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;padding:11px 15px 9px}
  .nfhub .pact-head h3{font-size:14px;font-weight:700;margin:0;color:var(--owe)}
  .nfhub .pact-head .pact-sub{font-size:12.5px;color:var(--ink2)}
  .nfhub .pact-head .spacer{flex:1}
  .nfhub .pact-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
    padding:10px 15px;border-top:1px solid color-mix(in srgb,var(--owe) 18%,transparent)}
  .nfhub .pact-row.done{opacity:.45}
  .nfhub .pact-main{flex:1;min-width:200px}
  .nfhub .pact-main b{font-size:13.5px;display:block}
  .nfhub .pact-main .pact-meta{font-size:11.5px;color:var(--ink2);margin-top:2px}
  .nfhub .pact-amt{font-weight:700;font-size:14px;white-space:nowrap}
  .nfhub .pact-amt.in{color:var(--in)} .nfhub .pact-amt.out{color:var(--out)}
  .nfhub .pact-btns{display:flex;gap:6px;flex-wrap:wrap}
  .nfhub .pact-note{padding:9px 15px;font-size:11.5px;color:var(--ink2);
    border-top:1px solid color-mix(in srgb,var(--owe) 18%,transparent)}
  .nfhub .day-head .day-pending{color:var(--owe);font-weight:600}

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
  /* Line-item entry (weighted purchase) needs real width — it is a small spreadsheet. */
  .hubmodal-box.xwide{max-width:900px}

  /* Weighted-purchase line grid. GRID, not flex: flex children default to min-width:auto, so a
     long product name forced the row wider than the modal and the whole box scrolled sideways.
     minmax(0,…) lets every column shrink instead. */
  .hubmodal .wt-head,
  .hubmodal .wt-line{display:grid;gap:8px;align-items:center;
    grid-template-columns:minmax(0,2.4fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1.2fr) 26px}
  .hubmodal .wt-head{font-size:10px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--ink3);font-weight:700;padding:2px 0 5px}
  .hubmodal .wt-head span:not(:first-child){text-align:right}
  .hubmodal .wt-line{margin-bottom:6px}
  .hubmodal .wt-line select,
  .hubmodal .wt-line input{min-width:0;width:100%;border:1px solid var(--line);border-radius:8px;
    padding:7px 8px;background:var(--surface);color:var(--ink);font-size:12.5px}
  .hubmodal .wt-line .wt-qty,
  .hubmodal .wt-line .wt-rate{text-align:right}
  .hubmodal .wt-line .wt-lt{text-align:right;font-weight:700;font-size:12.5px;white-space:nowrap}
  @media (max-width:720px){
    .hubmodal .wt-head{display:none}
    .hubmodal .wt-line{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 26px}
    .hubmodal .wt-line .wt-prod{grid-column:1 / -1}
    .hubmodal .wt-line .wt-lt{grid-column:1 / span 2}
  }
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

  /* vendors list: business-unit section header (combined scope only) */
  .nfhub tr.bu-sep > td{background:var(--surface2);border-top:2px solid var(--line);
    padding:7px 12px !important;white-space:nowrap}
  .nfhub tr.bu-sep.first > td{border-top:0}
  .nfhub .bu-name{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--ink2)}
  .nfhub .bu-count{font-size:11px;color:var(--ink3);margin-left:8px}
  .nfhub .bu-owed{font-size:11px;color:var(--owe);font-weight:700;margin-left:8px}

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

  /* drawer: line items of a weighted purchase + the attached bill photo */
  .drawer .d-item{display:flex;align-items:baseline;gap:8px;padding:6px 0;border-bottom:1px solid var(--line2)}
  .drawer .d-item:last-child{border-bottom:0}
  .drawer .di-name{flex:1;font-size:12.5px;color:var(--ink);font-weight:600;min-width:0;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .drawer .di-calc{font-size:11px;color:var(--ink3);white-space:nowrap}
  .drawer .di-total{font-size:12.5px;color:var(--ink);white-space:nowrap}
  .drawer #nfhubDImg{max-width:100%;max-height:260px;border-radius:10px;border:1px solid var(--line);display:block}

  /* drawer: which physical bank an online row went through (hidden for cash-only rows) */
  .drawer .d-bank{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:3px 10px;
    border-radius:99px;background:var(--surface2);border:1px solid var(--line);font-size:11.5px}
  .drawer .d-bank b{font-weight:800;color:var(--ink);letter-spacing:.01em}

  /* image manager (edit surfaces): current images as thumbs, ✕ toggles removal-on-save */
  .nfhub .img-mgr{display:flex;flex-wrap:wrap;gap:8px;margin:4px 0 8px}
  .nfhub .img-mgr .im-th{position:relative;display:inline-block}
  .nfhub .img-mgr .im-th img{width:64px;height:64px;object-fit:cover;border-radius:8px;
    border:1px solid var(--line);display:block}
  .nfhub .img-mgr .im-th .im-x{position:absolute;top:-6px;right:-6px;width:20px;height:20px;
    border-radius:50%;border:1px solid var(--line);background:var(--surface);color:var(--ink2);
    font-size:11px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;
    box-shadow:0 1px 3px rgba(0,0,0,.15)}
  .nfhub .img-mgr .im-th .im-x:hover{background:var(--out-soft);color:var(--out);border-color:var(--out)}
  .nfhub .img-mgr .im-th.rm img{opacity:.3;outline:2px dashed var(--out);outline-offset:-2px}
  .nfhub .img-mgr .im-th.rm .im-x{background:var(--out);color:#fff;border-color:var(--out)}
  /* drawer view gallery */
  .drawer .d-gallery{display:flex;flex-wrap:wrap;gap:8px}
  .drawer .d-gallery img{width:84px;height:84px;object-fit:cover;border-radius:9px;
    border:1px solid var(--line);display:block}

  /* drawer: inline quick edit (vendor statement rows only) */
  .drawer .d-edit{display:flex;flex-direction:column;gap:10px}
  .drawer .d-edit label{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:700;
    letter-spacing:.04em;text-transform:uppercase;color:var(--ink3)}
  .drawer .d-edit input,.drawer .d-edit textarea{width:100%;border:1px solid var(--line);border-radius:8px;
    background:var(--surface);color:var(--ink);padding:8px 10px;font:inherit;font-size:13px;
    text-transform:none;letter-spacing:0;font-weight:500}
  .drawer .d-edit textarea{resize:vertical;min-height:52px}
  .drawer .d-edit input:focus,.drawer .d-edit textarea:focus{outline:none;border-color:var(--accent)}
  .drawer .d-edit input[type=file]{padding:6px 8px;font-size:12px}
  .drawer .d-edit-opt{text-transform:none;letter-spacing:0;font-weight:500;color:var(--ink3);font-size:10.5px}
  .drawer .d-edit-note{font-size:11px;color:var(--ink3);line-height:1.45}
  .drawer .d-edit-err{display:none;background:var(--out-soft);color:var(--out);border-radius:8px;
    padding:8px 12px;font-size:12.5px;font-weight:600;margin-bottom:10px}
  /* bank re-tag chips inside the drawer (the .bankchip rules above are .hubmodal-scoped) */
  .drawer .bankchips{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 2px}
  .drawer .bankchip{padding:5px 11px;border-radius:8px;border:1px solid var(--line);background:var(--surface);
    font-size:12px;font-weight:700;cursor:pointer;color:var(--ink2)}
  .drawer .bankchip.on{border-color:var(--accent);background:var(--accent-soft);color:var(--accent)}
  /* .foot-btns (flex row, equal-width buttons) is already defined above — reused as is. */

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

  /* ==========================================================================
     VENDORS LIST — state colour system.
     Borrowed from the Online Approvals page: every row states what it IS in
     colour, every button states what it DOES in colour. Four states, one
     meaning each:
       amber (owe)  = NF owes this vendor
       red   (out)  = NF owes AND no payment for 30+ days   <- the one to act on
       green (in)   = settled, but active in the period
       grey  (ink3) = idle — no balance, no movement this period
     Everything here is namespaced to vendors-page classes (.vstile, .vchip,
     .v-row, .vstatus, .tc-*, .mini-btn modifiers) so no other Hub page moves.
     ========================================================================== */

  /* state tiles — the old info tiles, now clickable filters */
  .nfhub .vstate{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:12px;margin-bottom:12px}
  .nfhub .vstile{background:var(--surface);border:1.5px solid var(--line);border-top-width:4px;border-radius:12px;
    padding:12px 15px;text-align:left;box-shadow:var(--shadow);cursor:pointer;width:100%;display:block;
    transition:transform .12s,box-shadow .12s,background .12s,border-color .12s}
  .nfhub .vstile:hover{transform:translateY(-1px);box-shadow:var(--shadow-lg)}
  .nfhub .vstile .v-label{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);
    font-weight:800;display:flex;align-items:center;gap:6px}
  .nfhub .vstile .v-count{font-size:24px;font-weight:800;margin:3px 0 1px;line-height:1.1}
  .nfhub .vstile .v-money{font-size:12.5px;font-weight:700;color:var(--ink2)}
  .nfhub .vstile.s-all{border-top-color:var(--info)}
  .nfhub .vstile.s-owes{border-top-color:var(--owe)}      .nfhub .vstile.s-owes .v-money{color:var(--owe)}
  .nfhub .vstile.s-stale{border-top-color:var(--out)}     .nfhub .vstile.s-stale .v-money{color:var(--out)}
  .nfhub .vstile.s-settled{border-top-color:var(--in)}    .nfhub .vstile.s-settled .v-money{color:var(--in)}
  .nfhub .vstile.on.s-all{background:var(--info-soft);border-color:var(--info)}
  .nfhub .vstile.on.s-owes{background:var(--owe-soft);border-color:var(--owe)}
  .nfhub .vstile.on.s-stale{background:var(--out-soft);border-color:var(--out)}
  .nfhub .vstile.on.s-settled{background:var(--in-soft);border-color:var(--in)}
  .nfhub .vstile[data-empty="1"]{opacity:.5}

  /* period figures (the old Purchases/Payments tiles) kept as one quiet strip */
  .nfhub .period-strip{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-bottom:14px;font-size:12.5px;color:var(--ink2)}
  .nfhub .period-strip .pchip{background:var(--surface);border:1px solid var(--line);border-radius:99px;
    padding:4px 13px;display:inline-flex;gap:7px;align-items:center;box-shadow:var(--shadow);white-space:nowrap}
  .nfhub .period-strip .pchip b{font-weight:700;color:var(--ink)}
  .nfhub .period-strip .pchip b.g{color:var(--in)} .nfhub .period-strip .pchip b.o{color:var(--owe)}

  /* filter chips (type / business unit) inside the filter bar */
  .nfhub .chip-rows{display:flex;flex-direction:column;gap:8px;margin-top:10px;padding-top:10px;
    border-top:1px dashed var(--line2)}
  .nfhub .chip-row{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
  .nfhub .chip-row .c-label{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);
    font-weight:700;min-width:42px}
  .nfhub .vchip{border:1.5px solid var(--line);background:var(--surface);color:var(--ink2);border-radius:99px;
    padding:4px 13px;font-size:12px;font-weight:700;cursor:pointer;transition:all .12s;
    display:inline-flex;gap:6px;align-items:center;white-space:nowrap}
  .nfhub .vchip:hover{border-color:var(--ink3);color:var(--ink)}
  .nfhub .vchip .cdot{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
  .nfhub .vchip .cnt{background:var(--surface2);border-radius:99px;padding:0 7px;font-size:11px;color:var(--ink3)}
  .nfhub .vchip.on{background:var(--info);border-color:var(--info);color:var(--info-ink)}
  .nfhub .vchip.on .cnt{background:color-mix(in srgb,var(--info-ink) 22%,transparent);color:var(--info-ink)}
  /* unselected chips still carry a hint of the colour they stand for */
  .nfhub .vchip.c-weight{border-color:color-mix(in srgb,var(--info) 45%,transparent)}
  .nfhub .vchip.c-total{border-color:color-mix(in srgb,var(--accent) 45%,transparent)}
  .nfhub .vchip.c-weight.on{background:var(--info);border-color:var(--info)}
  .nfhub .vchip.c-total.on{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
  .nfhub .vchip.c-total.on .cnt{color:var(--accent-ink)}

  /* legend in the card header — replaces the "amber = NF owes" footnote */
  .nfhub .legend{display:flex;gap:13px;flex-wrap:wrap;font-size:11.5px;color:var(--ink2);align-items:center}
  .nfhub .legend span{display:inline-flex;align-items:center;gap:5px;font-weight:600;white-space:nowrap}
  .nfhub .legend i{width:9px;height:9px;border-radius:3px;display:inline-block;flex:0 0 auto}
  .nfhub .legend .l-owe i{background:var(--owe)}
  .nfhub .legend .l-stale i{background:var(--out)}
  .nfhub .legend .l-ok i{background:var(--in)}
  .nfhub .legend .l-idle i{background:var(--ink3);opacity:.5}

  /* vendor rows: 4px left state bar + a faint tint of the same colour */
  .nfhub table.vendors-table{min-width:900px}
  .nfhub tbody tr.v-row{cursor:pointer}
  .nfhub tbody tr.v-row > td:first-child{border-left:4px solid transparent}
  .nfhub tbody tr.v-row.st-owes > td{background:color-mix(in srgb,var(--owe) 5%,var(--surface))}
  .nfhub tbody tr.v-row.st-owes > td:first-child{border-left-color:var(--owe)}
  .nfhub tbody tr.v-row.st-stale > td{background:color-mix(in srgb,var(--out) 5%,var(--surface))}
  .nfhub tbody tr.v-row.st-stale > td:first-child{border-left-color:var(--out)}
  .nfhub tbody tr.v-row.st-settled > td{background:color-mix(in srgb,var(--in) 4%,var(--surface))}
  .nfhub tbody tr.v-row.st-settled > td:first-child{border-left-color:var(--in)}
  .nfhub tbody tr.v-row.st-idle > td{background:var(--surface)}
  .nfhub tbody tr.v-row.st-idle > td:first-child{border-left-color:var(--line)}
  .nfhub tbody tr.v-row.st-idle .v-name,.nfhub tbody tr.v-row.st-idle .amt{opacity:.62}
  /* hover has to out-specify the tint above, so it is stated per state */
  .nfhub tbody tr.v-row.st-owes:hover > td{background:color-mix(in srgb,var(--owe) 12%,var(--surface))}
  .nfhub tbody tr.v-row.st-stale:hover > td{background:color-mix(in srgb,var(--out) 12%,var(--surface))}
  .nfhub tbody tr.v-row.st-settled:hover > td{background:color-mix(in srgb,var(--in) 10%,var(--surface))}
  .nfhub tbody tr.v-row.st-idle:hover > td{background:var(--surface2)}

  /* vendor name cell */
  .nfhub .v-name{font-weight:600;display:flex;align-items:center;gap:9px}
  .nfhub tr.v-row .avatar{width:30px;height:30px;font-size:11px;font-weight:800}
  .nfhub tr.v-row.st-owes .avatar{background:var(--owe-soft);color:var(--owe)}
  .nfhub tr.v-row.st-stale .avatar{background:var(--out-soft);color:var(--out)}
  .nfhub tr.v-row.st-settled .avatar{background:var(--in-soft);color:var(--in)}
  .nfhub tr.v-row.st-idle .avatar{background:var(--surface2);color:var(--ink3)}
  .nfhub .v-code{font-family:"Cascadia Mono",Consolas,ui-monospace,monospace;font-size:11px;
    color:var(--ink3);margin-left:39px;display:block}

  /* purchase-method chip, coloured by method (weight = blue, total = green) */
  .nfhub .type-chip.tc-weight{background:var(--info-soft);color:var(--info)}
  .nfhub .type-chip.tc-total{background:var(--accent-soft);color:var(--accent)}

  /* state pill — same recipe as the existing .status pills */
  .nfhub .status.owes{background:var(--owe-soft);color:var(--owe)}
  .nfhub .status.stale{background:var(--out-soft);color:var(--out)}
  .nfhub .status.idle{background:var(--surface2);color:var(--ink3)}

  /* last-payment freshness under the date */
  .nfhub .cell-date .ago{display:block;font-size:10.5px;font-weight:700;color:var(--ink3)}
  .nfhub .cell-date .ago.fresh{color:var(--in)}
  .nfhub .cell-date .ago.old{color:var(--out)}

  /* amount colours in the list */
  .nfhub .amt.zero{color:var(--ink3);font-weight:600}
  .nfhub td.cell-num{color:var(--ink2)}

  /* action buttons — colour by meaning, not all identical grey */
  .nfhub .mini-btn.solid-info{background:var(--info);border-color:var(--info);color:var(--info-ink)}
  .nfhub .mini-btn.solid-info:hover{color:var(--info-ink);filter:brightness(1.1)}
  .nfhub .mini-btn.danger{color:var(--out);border-color:color-mix(in srgb,var(--out) 40%,transparent)}
  .nfhub .mini-btn.danger:hover{background:var(--out-soft);border-color:var(--out);color:var(--out)}

  /* business-unit section header: owed total as a pill */
  .nfhub .bu-owed{display:inline-flex;align-items:center;gap:5px;background:var(--owe-soft);
    border-radius:99px;padding:1px 10px;font-weight:800}
  .nfhub .bu-owed::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}

  @media (max-width:760px){
    .nfhub .chip-row .c-label{min-width:0;width:100%}
    .nfhub .card-head .legend{width:100%}
  }

  /* ==========================================================================
     VENDOR STATEMENT (detail page) — the same colour grammar as the list.
     A vendor statement only ever holds two kinds of row, and they are exact
     opposites:  amber = purchase (grows what NF owes) · green = payment (pays
     it down). Those were already the two column colours; this puts the same
     pair on the row, the type chip, the day/month sums and the quick-add
     buttons, so the statement can be read by shape instead of by number.
     ⚠ vendor-day-groups.blade.php is ALSO the lazy-loaded month response, so
     everything here is pure CSS on server-rendered classes — never page-load JS.
     ========================================================================== */

  /* balance header: state accent + inline pill */
  .nfhub .bal-head.vs-owes{border-left:4px solid var(--owe)}
  .nfhub .bal-head.vs-stale{border-left:4px solid var(--out)}
  .nfhub .bal-head.vs-settled{border-left:4px solid var(--in)}
  .nfhub .b-label .b-pill{text-transform:none;letter-spacing:0;margin-left:8px;vertical-align:1px}

  /* stat chips coloured by what they measure */
  .nfhub .stat-chip.sc-owe{background:var(--owe-soft)} .nfhub .stat-chip.sc-owe b{color:var(--owe)}
  .nfhub .stat-chip.sc-in{background:var(--in-soft)}   .nfhub .stat-chip.sc-in b{color:var(--in)}
  .nfhub .stat-chip.sc-old{background:var(--out-soft)} .nfhub .stat-chip.sc-old b{color:var(--out)}
  .nfhub .stat-chip .sc-ago{display:block;font-size:10.5px;font-weight:700;color:var(--ink3);margin-top:1px}
  .nfhub .stat-chip .sc-ago.fresh{color:var(--in)} .nfhub .stat-chip .sc-ago.old{color:var(--out)}

  /* the two write-actions carry the colour of the column they write into */
  .nfhub .btn.solid-owe{background:var(--owe);border-color:var(--owe);color:var(--owe-ink)}
  .nfhub .btn.solid-owe:hover{color:var(--owe-ink);filter:brightness(1.06)}
  .nfhub .btn.solid-in{background:var(--in);border-color:var(--in);color:var(--in-ink)}
  .nfhub .btn.solid-in:hover{color:var(--in-ink);filter:brightness(1.06)}

  /* day / month sum pairs */
  .nfhub .s-pur{color:var(--owe);font-weight:700}
  .nfhub .s-pay{color:var(--in);font-weight:700}
  .nfhub .s-pur.z,.nfhub .s-pay.z{color:var(--ink3);font-weight:600}

  /* per-day quick-add buttons, tinted to their target column */
  .nfhub .mini-btn.tint-owe{color:var(--owe);border-color:color-mix(in srgb,var(--owe) 40%,transparent)}
  .nfhub .mini-btn.tint-owe:hover{background:var(--owe-soft);border-color:var(--owe);color:var(--owe)}
  .nfhub .mini-btn.tint-in{color:var(--in);border-color:color-mix(in srgb,var(--in) 40%,transparent)}
  .nfhub .mini-btn.tint-in:hover{background:var(--in-soft);border-color:var(--in);color:var(--in)}

  /* statement rows: 4px left bar + faint tint, purchase vs payment.
     Hover is restated per kind because the generic .t-row:hover would otherwise
     tie on specificity and wipe the tint. */
  .nfhub tbody tr.t-row.e-purchase > td:first-child{border-left:4px solid var(--owe)}
  .nfhub tbody tr.t-row.e-payment  > td:first-child{border-left:4px solid var(--in)}
  .nfhub tbody tr.t-row.e-purchase > td{background:color-mix(in srgb,var(--owe) 4%,var(--surface))}
  .nfhub tbody tr.t-row.e-payment  > td{background:color-mix(in srgb,var(--in) 4%,var(--surface))}
  .nfhub tbody tr.t-row.e-purchase:hover > td{background:color-mix(in srgb,var(--owe) 11%,var(--surface))}
  .nfhub tbody tr.t-row.e-payment:hover  > td{background:color-mix(in srgb,var(--in) 10%,var(--surface))}

  /* entry type chip */
  .nfhub .type-chip.tc-purchase{background:var(--owe-soft);color:var(--owe)}
  .nfhub .type-chip.tc-payment{background:var(--in-soft);color:var(--in)}

  /* placeholder */
  .nfhub .ph{background:var(--surface);border:1px dashed var(--line);border-radius:var(--radius);
    padding:40px 24px;text-align:center;box-shadow:var(--shadow)}
  .nfhub .ph h2{margin:0 0 6px;font-size:18px}
  .nfhub .ph p{color:var(--ink2);font-size:13.5px;margin:0 auto 16px;max-width:520px}
</style>
@endpush
