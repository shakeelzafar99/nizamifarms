{{--
    Bikes (Jul-2026) — what each rider's bike costs per month.

    Self-contained like Day Review: own markup, .fl-* styles, fl* state.
    Reads /orders/riders-map/fleet[/rider]. Gated server-side; the tab button
    is only rendered for users who pass the same gate.

    The screen's job is a decision, not a report: company bikes vs own bikes,
    cost per PRODUCTIVE kilometre, with and without maintenance.
--}}

<div id="fleetView" style="display: none;">

    <div class="fl-bar">
        {{-- Riders = what each rider's bike COSTS (the original screen, untouched).
             Vehicles = the machines themselves and who has them (Aug-2026).
             Two questions about the same fleet, so they live in one tab rather
             than a new sidebar entry. --}}
        <div class="fl-modes">
            <button type="button" id="flModeRiders" class="fl-mode on" onclick="flSetMode('riders')">👤 Riders</button>
            <button type="button" id="flModeVehicles" class="fl-mode" onclick="flSetMode('vehicles')">🏍️ Vehicles</button>
        </div>
        <div class="fl-monthwrap" id="flMonthWrap">
            <button class="fl-nav" onclick="flShiftMonth(-1)" title="Previous month">‹</button>
            <input type="month" id="flMonthInput" onchange="flLoad(this.value)">
            <button class="fl-nav" onclick="flShiftMonth(1)" title="Next month">›</button>
        </div>
        <div id="flHeadline" class="fl-headline"></div>
        {{-- ⛽/🔧 File a bike expense WITHOUT leaving Bikes. Opens a small modal
             here rather than navigating to the full request form — that form has
             a company-wide employee dropdown (long, and the names render cut off)
             and it takes the manager away from the numbers he is looking at.
             The rider list here is the Bikes roster, already loaded.
             Posts to the SAME endpoint, so the same FuelClaimRules apply. --}}
        <div class="fl-newreq">
            <button type="button" class="fl-newreqbtn" onclick="flOpenNew('Petrol')" title="File a petrol claim for a rider">⛽ New petrol</button>
            <button type="button" class="fl-newreqbtn" onclick="flOpenNew('Maintenance')" title="File a maintenance claim for a rider">🔧 New maintenance</button>
            {{-- Shown only to whoever may change service schedules (manage_bike_service).
                 Hidden entirely for everyone else rather than shown-and-refused. --}}
            <button type="button" class="fl-newreqbtn" id="flTypesBtn" style="display:none;"
                    onclick="flOpenTypes()" title="Add or edit the maintenance categories">⚙️ Types</button>
        </div>
    </div>

    <div id="flVerdict" class="fl-verdict" style="display:none;"></div>
    <div id="flNotes" class="fl-notes" style="display:none;"></div>

    <div class="fl-tablewrap">
        <table class="fl-table" id="flTable">
            <thead>
                <tr>
                    <th>Rider</th>
                    <th>Bike</th>
                    <th class="num">Work km</th>
                    <th class="num" title="Meter-out at home → next morning's meter-in, on a night he had the bike at both ends. Real commuting only.">Off-duty</th>
                    {{-- ⭐ Aug-2026: kilometres that belong to NOBODY. A handover day's
                         run is real but unsplittable, and the bike travelling between
                         two riders is nobody's personal usage. Before this column they
                         were dumped on whoever happened to hold the bike. --}}
                    <th class="num" title="Kilometres on a day the bike changed hands (real, but impossible to split between the two riders) and the bike's own travel between them. Counted against neither man.">Shared / transit</th>
                    {{-- "Costed km" used to show WORK km, while the firm was demonstrably
                         paying for the commute too. Fuelled km = every kilometre whose
                         petrol this company bought. --}}
                    <th class="num" title="Every km we bought the fuel for: work + commute on a company bike, shift km on an own bike">Fuelled km</th>
                    <th class="num">Fuel</th>
                    {{-- ONE rate per row (owner: avoid clutter): fuel ÷ every km we
                         fuelled — what a kilometre on this machine costs to run. The
                         company-vs-own COMPARISON lives in the banner above and is
                         computed on productive km, which is stated there. --}}
                    <th class="num" title="Fuel ÷ every km we fuelled (work + commute on a company bike). What a kilometre on this machine costs to run. The company-vs-own comparison in the banner uses productive km instead — see the note there.">Rs/km <span style="font-weight:400;font-size:9.5px;">ridden</span></th>
                    <th class="num">Maint.</th>
                    <th class="num" title="(Fuel + maintenance) ÷ the same kilometres as the Rs/km beside it">Rs/km all-in</th>
                    <th>Service</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="flBody">
                <tr><td colspan="12" class="fl-empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <div id="flDetail" class="fl-detail" style="display:none;"></div>

    {{-- ═══ VEHICLES (Aug-2026) ═══
         The machines as things in their own right: what they are, who has them,
         what condition they were handed over in. Hidden until the toggle is used,
         so the costs screen opens exactly as it always did. --}}
    <div id="flVehWrap" style="display:none;">
        <div id="flVehIntro" class="fl-vintro"></div>
        <div id="flVehGrid" class="fl-vgrid"><div class="fl-empty">Loading…</div></div>
        <div id="flVehDetail" class="fl-vdetail" style="display:none;"></div>
    </div>
</div>

<style>
/* ---------- Bikes (scoped .fl-*) ---------- */
.fl-bar{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb;}
.fl-monthwrap{display:flex;align-items:center;gap:6px;}
.fl-monthwrap input[type=month]{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.fl-nav{border:1px solid #d1d5db;background:#fff;border-radius:6px;width:28px;height:30px;cursor:pointer;font-size:16px;line-height:1;color:#374151;}
.fl-nav:hover{background:#f3f4f6;}
.fl-headline{font-size:12.5px;color:#6b7280;}
.fl-headline b{color:#111827;}

.fl-newreq{display:flex;gap:8px;margin-left:auto;}
.fl-newreqbtn{display:inline-block;padding:6px 12px;border:1px solid #fcd34d;background:#fff;color:#92400e;border-radius:7px;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;}
.fl-newreqbtn:hover{background:#fffbeb;}
.fl-verdict{margin:12px 16px 0;padding:11px 14px;border-radius:8px;background:#f0fdf4;border:1px solid #86efac;font-size:13px;color:#14532d;line-height:1.5;}
.fl-verdict.tie{background:#fffbeb;border-color:#fcd34d;color:#78350f;}
.fl-verdict b{font-weight:700;}
/* Compact comparison instead of a paragraph: two rows, three numbers each, read
   left to right. A manager wants the figures, not the essay. */
.fl-cmp{width:auto;border-collapse:collapse;font-size:13px;}
.fl-cmp th{font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;opacity:.6;padding:0 14px 4px 0;text-align:right;}
.fl-cmp th:first-child{text-align:left;}
.fl-cmp td{padding:3px 14px 3px 0;text-align:right;font-variant-numeric:tabular-nums;}
.fl-cmp td:first-child{text-align:left;font-weight:600;padding-right:22px;}
.fl-cmp .big{font-size:16px;font-weight:700;}
.fl-cmp .dim{opacity:.55;}
.fl-cmpwin{font-size:11.5px;font-weight:700;padding-top:6px;}
.fl-cmpfoot{font-size:11px;opacity:.65;padding-top:7px;line-height:1.45;}
.fl-notes{margin:10px 16px 0;padding:9px 13px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;font-size:12.5px;color:#7f1d1d;}

.fl-tablewrap{padding:12px 16px;overflow-x:auto;}
.fl-table{border-collapse:collapse;width:100%;font-size:13px;min-width:940px;background:#fff;}
.fl-table th{font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:600;background:#f9fafb;white-space:nowrap;}
.fl-table td{padding:9px 10px;border-bottom:1px solid #f1f5f9;white-space:nowrap;font-variant-numeric:tabular-nums;}
.fl-table th.num,.fl-table td.num{text-align:right;}
.fl-table tbody tr{cursor:pointer;}
.fl-table tbody tr:hover{background:#f9fafb;}
.fl-table tbody tr.sel{background:#fffbeb;}
.fl-name{font-weight:600;color:#111827;}
.fl-empty{text-align:center;color:#9ca3af;padding:22px;font-style:normal;}
.fl-muted{color:#9ca3af;}
.fl-strong{font-weight:700;color:#111827;}

.fl-pill{display:inline-block;font-size:11px;border-radius:999px;padding:1.5px 9px;font-weight:600;}
.fl-company{background:#e0e7ff;color:#3730a3;}
.fl-own{background:#f3f4f6;color:#4b5563;}
.fl-unknown{background:#fee2e2;color:#b91c1c;}
/* Shared / transit — kilometres charged to nobody. Deliberately its own colour:
   it must not read as either a warning (the rider did nothing wrong) or as an
   ordinary figure (it is not his). */
.fl-shared{background:#e6f7f3;color:#0f766e;}
.fl-mrow{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;padding:6px 8px;
         border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;margin-bottom:6px;}
.fl-mrow .fl-mkm{font-weight:700;}
.fl-mlink{color:#1d4ed8;cursor:pointer;font-size:11.5px;font-weight:600;}
.fl-mlink:hover{text-decoration:underline;}
.fl-handover{background:#e6f7f3;border-left:3px solid #0f766e;border-radius:6px;
             padding:4px 8px;margin:3px 0;}
/* Day cards — one card per date, the day read top to bottom. */
.fl-dc{border:1px solid #e5e7eb;border-radius:9px;margin-bottom:9px;overflow:hidden;}
.fl-dc-h{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
         background:#f8f9fb;border-bottom:1px solid #e5e7eb;padding:6px 11px;
         font-weight:650;font-size:13px;color:#111827;}
.fl-dc-b{padding:6px 11px 8px;}
.fl-dc-l{display:flex;gap:9px;padding:2.5px 0;font-size:12.5px;align-items:baseline;flex-wrap:wrap;}
.fl-dc-k{min-width:92px;color:#6b7280;font-size:11.5px;flex-shrink:0;}
.fl-dc-l b{font-size:13px;color:#111827;}
.fl-ok{background:#dcfce7;color:#15803d;}
.fl-due{background:#fef3c7;color:#b45309;}
.fl-over{background:#fee2e2;color:#b91c1c;}
.fl-na{background:#f3f4f6;color:#9ca3af;}
.fl-warn{background:#fef3c7;color:#b45309;}

.fl-detail{margin:0 16px 18px;border:1px solid #e5e7eb;border-radius:9px;background:#fff;overflow:hidden;}
.fl-dhead{display:flex;align-items:center;gap:12px;padding:11px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;}
.fl-dhead h4{margin:0;font-size:14.5px;color:#111827;}
.fl-dclose{margin-left:auto;border:none;background:none;font-size:19px;color:#9ca3af;cursor:pointer;line-height:1;}
.fl-dbody{display:grid;grid-template-columns:1fr 300px;gap:0;}
@media (max-width:960px){.fl-dbody{grid-template-columns:1fr;}}
.fl-days{max-height:460px;overflow-y:auto;}
.fl-side{border-left:1px solid #e5e7eb;padding:12px 14px;}
@media (max-width:960px){.fl-side{border-left:0;border-top:1px solid #e5e7eb;}}
.fl-side h5{margin:0 0 8px;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;}
.fl-day{padding:9px 14px;border-bottom:1px solid #f1f5f9;}
.fl-dayhead{display:flex;align-items:center;gap:10px;font-size:12.5px;}
.fl-daydate{font-weight:600;color:#111827;min-width:96px;}
.fl-daykm{color:#6b7280;font-variant-numeric:tabular-nums;}
.fl-daymissing{color:#b45309;font-weight:600;}
.fl-appnote{font-size:11.5px;color:#3730a3;margin-top:4px;}
.fl-maintsplit{font-size:10.5px;color:#6b7280;font-weight:500;margin-top:1px;}
.fl-appnote span{color:#9ca3af;}
.fl-appwho{font-size:11px;color:#4b5563;margin-top:3px;}
.fl-appwho b{color:#111827;font-weight:600;}
.fl-appwho .fl-muted{color:#9ca3af;}
.fl-kmcell.tap{cursor:pointer;}
.fl-kmcell.tap:hover{background:#eef2ff;border-color:#c7d2fe;}
.fl-offlist{margin-top:8px;border-top:1px solid #e5e7eb;padding-top:7px;}
.fl-offrow{display:flex;justify-content:space-between;font-size:12px;color:#374151;padding:3px 0;font-variant-numeric:tabular-nums;}
.fl-offrow span:last-child{font-weight:600;color:#b45309;}
.fl-kmbox{padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.fl-kmtitle{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fl-kmrow{display:flex;gap:8px;margin-top:7px;flex-wrap:wrap;}
.fl-kmcell{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:6px 14px;text-align:center;min-width:78px;}
.fl-kmcell b{display:block;font-size:14px;color:#111827;font-variant-numeric:tabular-nums;}
.fl-kmcell span{font-size:9.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.fl-kmcell.off b{color:#d97706;}
.fl-kmnote{font-size:11.5px;color:#b45309;margin-top:7px;font-weight:600;}
.fl-kmnote.dim{color:#9ca3af;font-weight:500;}
.fl-claim{display:flex;align-items:center;gap:9px;margin-top:6px;padding:6px 9px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;font-size:12.5px;flex-wrap:wrap;}
.fl-claim.flagged{background:#fffbeb;border-color:#fcd34d;}
.fl-claim.rejected{opacity:.55;}
.fl-thumb{width:38px;height:38px;object-fit:cover;border-radius:5px;border:1px solid #d1d5db;cursor:pointer;flex-shrink:0;background:#f3f4f6;}
.fl-nophoto{width:38px;height:38px;border-radius:5px;border:1px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:15px;flex-shrink:0;}
.fl-amt{font-weight:700;color:#111827;font-variant-numeric:tabular-nums;}
.fl-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-left:auto;}
.fl-src{border:1px solid #d1d5db;border-radius:6px;padding:3px 6px;font-size:11.5px;background:#fff;color:#374151;max-width:150px;}
.fl-approve,.fl-reject{border:none;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:700;color:#fff;cursor:pointer;white-space:nowrap;}
.fl-approve{background:#16a34a;}
.fl-approve:hover{background:#15803d;}
.fl-reject{background:#dc2626;}
.fl-reject:hover{background:#b91c1c;}
.fl-svc{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px solid #f1f5f9;}
.fl-btn{border:1px solid #d1d5db;background:#fff;border-radius:6px;padding:5px 11px;cursor:pointer;font-size:12px;color:#374151;}
.fl-btn:hover{background:#f3f4f6;}

/* ---------- Vehicles view (Aug-2026, scoped .fl-v*) ---------- */
.fl-modes{display:flex;gap:0;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;}
.fl-mode{border:none;background:#fff;padding:6px 13px;font-size:12.5px;font-weight:700;color:#6b7280;cursor:pointer;white-space:nowrap;}
.fl-mode + .fl-mode{border-left:1px solid #d1d5db;}
.fl-mode.on{background:#f59e0b;color:#fff;}
.fl-mode:not(.on):hover{background:#f9fafb;}

.fl-vintro{margin:12px 16px 0;font-size:12px;color:#6b7280;line-height:1.5;}
.fl-vintro b{color:#111827;}
.fl-vwarn{margin:12px 16px 0;padding:10px 13px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;font-size:12.5px;color:#78350f;}
.fl-vgrid{padding:12px 16px;}
/* ⭐ Two sides: working pairs on the left, everything idle on the right. Collapses
   to one column on a narrow screen — the left side stays first, which is the side
   a manager reads every day. */
.fl-vsplit{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:start;}
@media (max-width:1100px){.fl-vsplit{grid-template-columns:1fr;}}
.fl-vcol{min-width:0;display:flex;flex-direction:column;gap:10px;}
.fl-vcolh{margin:0 0 2px;font-size:12px;font-weight:800;color:#6b7280;text-transform:uppercase;
  letter-spacing:.05em;display:flex;align-items:center;gap:7px;}
.fl-vcolh span{background:#f3f4f6;color:#4b5563;border-radius:20px;padding:1px 8px;font-size:11px;letter-spacing:0;}
.fl-vsubh{margin-top:6px;font-size:11.5px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;}
.fl-vretired{margin-top:6px;}
.fl-vretired summary{font-size:11.5px;color:#9ca3af;cursor:pointer;padding:4px 0;}
.fl-vretired[open] summary{margin-bottom:8px;}
.fl-vcard{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:12px;cursor:pointer;transition:box-shadow .12s,border-color .12s;}
.fl-vcard:hover{border-color:#fcd34d;box-shadow:0 3px 12px rgba(0,0,0,.07);}
.fl-vcard.retired{opacity:.55;}
/* Per-type service schedule rows on the machine profile. */
.fl-vsched{margin-top:9px;display:flex;flex-direction:column;}
.fl-vschedrow{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:12.5px;
  color:#374151;border-top:1px solid #f3f4f6;}
.fl-vschedrow:first-child{border-top:none;}
/* A rider holding nothing — a state to act on, not an error. */
.fl-vcard.fl-vrider{cursor:default;border-style:dashed;background:#fcfcfd;}
.fl-vcard.fl-vrider:hover{border-color:#e5e7eb;box-shadow:none;}
.fl-vnote{margin-top:8px;font-size:11.5px;line-height:1.45;color:#6b7280;}
.fl-vtop{display:flex;align-items:flex-start;gap:10px;}
.fl-vphoto{width:52px;height:52px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;background:#f3f4f6;flex-shrink:0;}
.fl-vnophoto{width:52px;height:52px;border-radius:8px;border:1px dashed #d1d5db;display:flex;align-items:center;justify-content:center;font-size:22px;color:#d1d5db;flex-shrink:0;}
.fl-vname{font-size:15px;font-weight:700;color:#111827;line-height:1.2;}
.fl-vsub{font-size:11.5px;color:#6b7280;margin-top:2px;}
.fl-vtag{display:inline-block;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;vertical-align:middle;margin-left:6px;}
.fl-vtag.co{background:#dbeafe;color:#1e40af;}
.fl-vtag.own{background:#f3f4f6;color:#4b5563;}
.fl-vtag.none{background:#fef3c7;color:#92400e;}
.fl-vkeeper{margin-top:9px;font-size:12.5px;color:#374151;}
.fl-vkeeper b{color:#111827;}
/* "Nobody has this one" is informational, not a fault — amber, not red. */
.fl-vkeeper.none{color:#9ca3af;font-weight:600;}
/* An own bike whose owner is out on a company machine: exactly where it should be. */
.fl-vkeeper.parked{color:#6b7280;}
/* Standing "needs a home pin" nag — amber, informative, self-clearing. */
.fl-vwarnpin{margin-top:6px;font-size:11.5px;line-height:1.45;color:#92400e;
  background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 8px;}
.fl-vstats{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#4b5563;font-variant-numeric:tabular-nums;}
.fl-vchip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.fl-vchip.ok{background:#dcfce7;color:#166534;}
.fl-vchip.due{background:#fef3c7;color:#92400e;}
.fl-vchip.over{background:#fee2e2;color:#991b1b;}
.fl-vchip.unk{background:#f3f4f6;color:#6b7280;}
.fl-vfoot{display:flex;align-items:center;gap:7px;margin-top:10px;padding-top:9px;border-top:1px solid #f1f5f9;}
.fl-vbtn{border:1px solid #d1d5db;background:#fff;border-radius:7px;padding:5px 11px;font-size:12px;font-weight:600;color:#374151;cursor:pointer;}
.fl-vbtn:hover{background:#f3f4f6;}
.fl-vbtn.primary{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.fl-vbtn.primary:hover{background:#d97706;}

.fl-vdetail{margin:0 16px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;overflow:hidden;}
.fl-vdhead{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#fafafa;flex-wrap:wrap;}
.fl-vdbody{padding:14px 16px;}
.fl-vsec{margin-bottom:16px;}
.fl-vsec h4{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:0 0 8px;}
.fl-vstrip{display:flex;gap:9px;overflow-x:auto;padding-bottom:4px;}
.fl-vpic{flex-shrink:0;width:104px;}
.fl-vpic img{width:104px;height:82px;object-fit:cover;border-radius:7px;border:1px solid #e5e7eb;cursor:pointer;background:#f3f4f6;}
.fl-vpiclab{font-size:10.5px;color:#6b7280;margin-top:3px;line-height:1.3;}
.fl-vhrow{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12.5px;}
.fl-vhrow:last-child{border-bottom:none;}
.fl-vhnow{background:#dcfce7;color:#166534;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;}

.fl-vdaychip{display:inline-block;margin-left:7px;padding:1px 7px;border-radius:20px;font-size:10.5px;font-weight:700;background:#f3f4f6;color:#4b5563;vertical-align:middle;}
.fl-vdaychip.fix{background:#e0e7ff;color:#3730a3;}
.fl-vdaychip.xfer{background:#fef3c7;color:#92400e;}
.fl-vdaylink{margin-left:7px;font-size:10.5px;font-weight:700;color:#9ca3af;text-decoration:none;vertical-align:middle;}
.fl-vdaylink:hover{color:#b45309;text-decoration:underline;}

/* photo lightbox (inline-styled shell — the purged utility classes cannot be trusted here) */
#flLightbox{display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:4000;background:rgba(0,0,0,.75);align-items:center;justify-content:center;}
#flLightbox img{max-width:92vw;max-height:88vh;border-radius:8px;box-shadow:0 12px 44px rgba(0,0,0,.5);background:#fff;}
#flLightbox .fl-lbclose{position:absolute;top:14px;right:20px;color:#fff;font-size:30px;cursor:pointer;line-height:1;background:none;border:none;}
</style>

<div id="flLightbox" onclick="flClosePhoto()">
    <button class="fl-lbclose" onclick="flClosePhoto()" title="Close">&times;</button>
    <img id="flLightboxImg" src="" alt="Receipt photo">
</div>

{{-- New bike expense. Inline-styled shell on purpose — this page's CSS is purged
     of the legacy utility classes, so a class-based modal renders top-left and
     will not scroll (see the metronic-v9 note). --}}
{{-- ⚙️ MAINTENANCE TYPES — the manager's own category list.
     Inline styles for the same reason as the modal below: this page's shell drops
     the legacy utility classes, so a class-based modal renders top-left and will
     not scroll (see the metronic-v9 note). --}}
<div id="flTypesModal" onclick="if(event.target===this)flCloseTypes()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b style="font-size:15px;color:#111827;">⚙️ Maintenance types</b>
      <button type="button" onclick="flCloseTypes()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <div style="font-size:11.5px;color:#6b7280;margin-bottom:10px;">
        The list riders and managers pick from when filing maintenance. A <b>Regular</b> type can
        carry a schedule (“every 1,200&nbsp;km”); a <b>Repair</b> happens as needed.
        Only a regular type that <b>resets the service clock</b> counts as “the bike was serviced” —
        tick it for oil services only, or a brake-shoe job will make the bike look serviced when it is not.
      </div>
      <div id="flTypesBody" style="font-size:13px;">Loading…</div>

      <div id="flTypesForm" style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;display:none;">
        <input type="hidden" id="flTypeId">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
          <div style="flex:2;min-width:150px;">
            <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Name</label>
            <input id="flTypeName" type="text" maxlength="80" placeholder="e.g. Brake Shoe"
                   style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          </div>
          <div style="flex:1;min-width:110px;">
            <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Kind</label>
            <select id="flTypeBucket" onchange="flTypeBucketChanged()"
                    style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
              <option value="regular">🛢️ Regular</option>
              <option value="repair">🔧 Repair</option>
            </select>
          </div>
          <div style="flex:1;min-width:110px;">
            <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Due every (km)</label>
            <input id="flTypeInterval" type="number" min="0" max="200000" step="100" placeholder="blank = as conditions"
                   style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          </div>
        </div>
        <label id="flTypeResetWrap" style="display:block;margin-top:8px;font-size:12.5px;color:#374151;">
          <input type="checkbox" id="flTypeResets"> This one resets the bike's service-due clock
        </label>
        <div id="flTypesError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
             border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:8px;"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button type="button" onclick="flSaveType()" id="flTypeSaveBtn"
                  style="padding:8px 14px;border:none;border-radius:8px;background:#f59e0b;color:#fff;
                         font-size:13px;font-weight:700;cursor:pointer;">Save</button>
          <button type="button" onclick="flResetTypeForm()"
                  style="padding:8px 14px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                         font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Clear</button>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- 🏍️ ASSIGN / REASSIGN a vehicle. Inline-styled shell for the same reason as the
     others (purged legacy classes render a class-based modal top-left, unscrollable).

     ⭐ The consequence preview is the point of this modal. Handing a bike over changes
     who is measured for its meter, whether the company buys the fuel, and whether the
     overnight checks can run at all — so the server is asked what WILL happen and the
     answer is shown in words before Save is ever pressed. --}}
<div id="flvAssignModal" onclick="if(event.target===this)flvCloseAssign()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:520px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flvAssignTitle" style="font-size:15px;color:#111827;">Assign vehicle</b>
      <button type="button" onclick="flvCloseAssign()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <input type="hidden" id="flvAssignVehicleId">

      {{-- Only shown when the manager came in from a RIDER card ("give him a bike"),
           where the machine is the thing still to be chosen. Hidden otherwise. --}}
      <div style="display:none;margin-bottom:10px;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Which machine</label>
        <select id="flvAssignVehicleSel" onchange="flvAssignVehicleChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 9px;font-size:13px;">
        </select>
      </div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Give it to</label>
      <select id="flvAssignRider" onchange="flvPreviewAssign()"
              style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 9px;font-size:13px;">
      </select>

      {{-- ⭐⭐ "AND WHAT ABOUT HIM?" (owner ask, Aug-6).
           Handing a machine over used to be half an act: the outgoing rider's
           assignment closed and nothing else happened, so he silently held nothing
           while the system still judged him as if he had a bike. Now the manager
           answers for him here, and the three options are the three real ones. --}}
      <div id="flvDisplacedBox" style="display:none;margin-top:12px;border:1px solid #fde68a;
           background:#fffbeb;border-radius:9px;padding:10px 11px;">
        <div id="flvDisplacedQ" style="font-size:12.5px;font-weight:700;color:#92400e;margin-bottom:7px;"></div>
        <div id="flvDisplacedOpts" style="display:flex;flex-direction:column;gap:5px;"></div>
        <select id="flvDisplacedVehicle" onchange="flvDisplacedPick('vehicle')"
                style="display:none;width:100%;margin-top:7px;border:1px solid #d1d5db;
                       border-radius:8px;padding:7px 9px;font-size:12.5px;"></select>
      </div>

      <div style="display:flex;gap:9px;margin-top:10px;">
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">From</label>
          <input type="date" id="flvAssignDate" onchange="flvPreviewAssign()"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
        <div style="flex:2;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Note <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
          <input type="text" id="flvAssignNote" maxlength="255" placeholder="e.g. Arslan on leave"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>

      {{-- ⭐ THE ODOMETER AT HANDOVER (owner ruling Aug-13).
           Without it, the day a bike changes hands shows as "shared" and is charged
           to neither rider — honest, but blunt. With it, the day splits exactly.
           ⚠ Optional and never blocking: the warning below is advice, not a gate. --}}
      <div style="margin-top:10px;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          Odometer at handover <span style="font-weight:400;color:#9ca3af;">(optional)</span>
        </label>
        <input type="number" id="flvAssignMeter" min="0" step="1" oninput="flvMeterHint()"
               placeholder="e.g. 25780"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        <div id="flvMeterHint" style="font-size:11.5px;color:#6b7280;margin-top:4px;">
          If you enter it, this day's kilometres split exactly between the two riders
          instead of showing as shared. Leave blank if you don't know it.
        </div>
      </div>

      {{-- Condition photos. Optional by design — a handover with no photo is still a
           handover, and blocking on a camera would just mean the assignment never
           gets recorded at all. --}}
      <div style="margin-top:11px;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          📷 Condition photos <span style="font-weight:400;color:#9ca3af;">(optional — how it looks at handover)</span>
        </label>
        <input type="file" id="flvAssignPhotos" accept="image/*" multiple
               style="width:100%;font-size:12px;">
      </div>

      <div id="flvPreviewBox" style="margin-top:12px;font-size:12.5px;"></div>

      <div id="flvAssignError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:9px;"></div>

      <div style="display:flex;gap:8px;margin-top:12px;">
        <button type="button" id="flvAssignSave" onclick="flvSaveAssign()"
                style="padding:9px 16px;border:none;border-radius:8px;background:#f59e0b;color:#fff;
                       font-size:13px;font-weight:700;cursor:pointer;">Assign</button>
        <button type="button" onclick="flvCloseAssign()"
                style="padding:9px 16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                       font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      </div>
    </div>
  </div>
</div>

{{-- ➕ ADD / EDIT a machine, and set the fixed base a van needs. --}}
<div id="flvEditModal" onclick="if(event.target===this)flvCloseEdit()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:500px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flvEditTitle" style="font-size:15px;color:#111827;">Add a vehicle</b>
      <button type="button" onclick="flvCloseEdit()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <input type="hidden" id="flvEditId">
      <div style="display:flex;gap:9px;flex-wrap:wrap;">
        <div style="flex:1;min-width:120px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Kind</label>
          <select id="flvEditType" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
            <option value="bike">🏍️ Bike</option>
            <option value="van">🚚 Van</option>
          </select>
        </div>
        <div style="flex:1;min-width:130px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Plate</label>
          <input id="flvEditReg" type="text" maxlength="32" placeholder="e.g. AY-4771"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
        <div style="flex:1;min-width:130px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Name <span style="font-weight:400;color:#9ca3af;">(if no plate)</span></label>
          <input id="flvEditNick" type="text" maxlength="64" placeholder="e.g. Van"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>
      <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:9px;">
        <div style="flex:2;min-width:150px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Make / model</label>
          <input id="flvEditModelName" type="text" maxlength="64" placeholder="e.g. Honda CG125"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
        <div style="flex:1;min-width:130px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Service every (km)</label>
          <input id="flvEditInterval" type="number" min="0" max="200000" step="100" placeholder="blank = company default"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>
      <label style="display:block;margin-top:9px;font-size:12.5px;color:#374151;">
        <input type="checkbox" id="flvEditCompany" checked> The company buys the fuel for this one
      </label>
      <label style="display:block;margin-top:5px;font-size:12.5px;color:#374151;">
        <input type="checkbox" id="flvEditActive" checked> In service
      </label>

      {{-- The base. A bike left blank behaves exactly as today: it sleeps wherever its
           rider does, measured against that rider's own home pin. Only a machine with a
           home of its own (the van's parking) needs a base here. --}}
      <div style="margin-top:12px;padding-top:11px;border-top:1px solid #e5e7eb;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          📍 Where it is parked overnight <span style="font-weight:400;color:#9ca3af;">(leave blank for a bike)</span>
        </label>
        <div style="font-size:11.5px;color:#6b7280;margin-bottom:6px;line-height:1.45;">
          Blank means it sleeps wherever its rider does, and the morning and overnight meter
          checks use <b>that rider's own home</b> — which is what every bike should do.
          Set a point only for a machine with a fixed home of its own, like the van's parking.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <input id="flvEditLat" type="text" placeholder="latitude"
                 style="flex:1;min-width:110px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          <input id="flvEditLng" type="text" placeholder="longitude"
                 style="flex:1;min-width:110px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          <input id="flvEditRadius" type="number" min="50" max="5000" step="50" placeholder="radius m"
                 style="width:110px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>

      <div id="flvEditError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:9px;"></div>

      <div style="display:flex;gap:8px;margin-top:12px;">
        <button type="button" onclick="flvSaveVehicle()" id="flvEditSave"
                style="padding:9px 16px;border:none;border-radius:8px;background:#f59e0b;color:#fff;
                       font-size:13px;font-weight:700;cursor:pointer;">Save</button>
        <button type="button" onclick="flvCloseEdit()"
                style="padding:9px 16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                       font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      </div>
    </div>
  </div>
</div>

{{-- ⚙️ COMPANY SERVICE SCHEDULE.
     Replaces a window.prompt that claimed "bikes with their own interval are
     unaffected" without ever naming one — so a manager raising the company schedule
     had no idea which machines would quietly ignore him. This names them and makes
     the choice explicit. --}}
<div id="flDefModal" onclick="if(event.target===this)flCloseDefault()"
     style="display:none;position:fixed;inset:0;z-index:4300;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:480px;max-height:90vh;
              display:flex;flex-direction:column;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;flex:0 0 auto;">
      <b style="font-size:15px;color:#111827;">🏢 Company service schedule</b>
      <button type="button" onclick="flCloseDefault()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:16px 18px;overflow-y:auto;flex:1 1 auto;min-height:0;">
      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
        Service every how many km?
      </label>
      <input id="flDefKm" type="number" min="100" max="100000" step="100"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:14px;box-sizing:border-box;">
      <div style="font-size:11.5px;color:#6b7280;margin-top:5px;">
        This is the schedule every bike follows unless it has one of its own.
      </div>
      <div id="flDefOverrides" style="margin-top:14px;"></div>
      <div id="flDefError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:8px 10px;margin-top:10px;"></div>
    </div>
    <div style="padding:12px 18px;border-top:1px solid #e5e7eb;background:#f9fafb;display:flex;
                gap:8px;justify-content:flex-end;flex:0 0 auto;">
      <button type="button" onclick="flCloseDefault()"
              style="padding:9px 16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                     font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      <button type="button" id="flDefSave" onclick="flSaveDefault()"
              style="padding:9px 18px;border:none;background:#4f46e5;color:#fff;border-radius:8px;
                     font-size:13px;font-weight:700;cursor:pointer;">Save schedule</button>
    </div>
  </div>
</div>

<div id="flNewModal" onclick="if(event.target===this)flCloseNew()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:440px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flNewTitle" style="font-size:15px;color:#111827;">⛽ New petrol request</b>
      <button type="button" onclick="flCloseNew()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;">

      <div>
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Rider</label>
        <select id="flNewRider" onchange="flNewRiderChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;"></select>
        <div id="flNewBikeHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
        {{-- ⭐ Which machine this claim lands on, from the registry, for the claim's
             own date — so nobody files against the wrong bike after a handover. --}}
        <div id="flNewWhichBike" style="display:none;font-size:11.5px;color:#0f766e;margin-top:4px;"></div>
      </div>

      {{-- ⭐ WHAT IS ALREADY ON RECORD FOR THIS BIKE (owner ask, Aug-16).
           "So they know what they are entering and what is already there, when, and
           by whom." Maintenance only — a petrol claim has its own since-last-fill
           line and this would just be noise on it. Filled by flNewWhichBike() from
           the same response that names the machine, so it can never disagree with
           the label directly above it. --}}
      <div id="flNewLastMaint" style="display:none;border:1px solid #e5e7eb;border-radius:8px;
           background:#f9fafb;padding:9px 11px;font-size:11.5px;line-height:1.55;"></div>

      {{-- 🔧 What was done. Populated from the manager's own maintenance types
           (grouped Regular / Repair). Falls back to the original two-option list
           when the types table is not there yet, so the form still works if the
           web files are uploaded before SQL batch 12 runs. --}}
      <div id="flNewSvcWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">What was done</label>
        <select id="flNewServiceType" onchange="flNewSvcChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
          <option value="">Not a bike / other maintenance</option>
          <option value="oil_change">🛢️ Regular service (oil change / tuning)</option>
          <option value="repair">🔧 Repair (anything broken)</option>
        </select>
        <div id="flNewSvcDue" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
      </div>

      <div style="display:flex;gap:10px;">
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Amount (Rs)</label>
          <input id="flNewAmount" type="number" min="1" step="0.01" placeholder="e.g. 1000"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        </div>
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Date</label>
          {{-- Changing the date can change the bike: a backdated claim belongs to
               whoever's machine it was THAT day. --}}
          <input id="flNewDate" type="date" onchange="flNewWhichBike()"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        </div>
      </div>

      {{-- 💳 Where the money actually comes from. Until Aug-2026 this form sent
           nothing, so every claim silently defaulted to the Expense Fund at
           posting time even when a manager had paid cash out of his own till.
           Options come from PaymentSourceService — the same rules the submit
           endpoint enforces, so nothing offered here can be rejected. --}}
      <div id="flNewPayWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">💳 Paid from</label>
        <select id="flNewPaySource" onchange="flNewPayChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;"></select>
        <div id="flNewPayHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
      </div>

      {{-- Only for a bank source: which of OUR banks the money left, so the
           per-bank balances stay right. Mandatory server-side. --}}
      <div id="flNewBankWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          🏦 From which bank <span style="font-weight:500;color:#b45309;">(required)</span>
        </label>
        <div id="flNewBankChips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
      </div>

      <div id="flNewMeterWrap">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          Bike meter reading <span id="flNewMeterReq" style="font-weight:500;color:#b45309;"></span>
        </label>
        <input id="flNewMeter" type="number" min="0" max="9999999" step="1" placeholder="Odometer (km)"
               oninput="flNewMeterTyped()"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        {{-- Live "that's N km since his last fill" — the same number the approver
             sees on the claim later, shown while the amount is still being typed. --}}
        <div id="flNewSince" style="font-size:11.5px;font-weight:700;color:#3730a3;margin-top:5px;display:none;"></div>
        <div id="flNewMeterHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
      </div>

      <div>
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Note (optional)</label>
        <input id="flNewNote" type="text" maxlength="200" placeholder="e.g. filled at Shell"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
      </div>

      <div id="flNewError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:8px 10px;"></div>
      {{-- ✏️ Doubles as the EDIT form: same fields, different verb. A separate
           modal would have meant two copies of the meter/type/date rules. --}}
      <div id="flNewEditNote" style="display:none;font-size:11.5px;color:#3730a3;background:#eef2ff;
           border:1px solid #c7d2fe;border-radius:8px;padding:8px 10px;"></div>
    </div>
    <div style="display:flex;gap:8px;padding:12px 18px;border-top:1px solid #e5e7eb;">
      <button type="button" onclick="flCloseNew()"
              style="flex:1;padding:9px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                     font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      <button type="button" id="flNewSubmit" onclick="flSubmitNew()"
              style="flex:2;padding:9px;border:none;background:#f59e0b;color:#fff;border-radius:8px;
                     font-size:13px;font-weight:700;cursor:pointer;">Create request</button>
    </div>
  </div>
</div>

<script>
// =============================================
// FLEET & FUEL — state
// =============================================
let flMonth = null;
let flData = null;
// The "expense" request category, needed to file a claim from the inline modal.
// Null if that category is ever missing — the buttons then explain rather than
// posting something the server would reject.
const flExpenseCategoryId = {{ $expenseCategoryId ?? 'null' }};
let flSelected = null;
let flInitDone = false;
let flApproval = null;    // what THIS user may approve + the payment sources
let flRider = null;       // the rider currently open in the drawer (for editing a claim)
let flCanManageService = false;   // may this user CHANGE service schedules?
let flDefaultInterval = 0;        // company-wide km between regular services

const FL_BASE = '/orders/riders-map/fleet';

function flInit() {
    if (!flInitDone) {
        const now = new Date();
        const m = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        const inp = document.getElementById('flMonthInput');
        inp.value = m;
        inp.max = m;
        flMonth = m;
        flInitDone = true;
    }
    flLoad(flMonth);
}

function flShiftMonth(delta) {
    const cur = document.getElementById('flMonthInput').value;
    const [y, m] = cur.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const now = new Date();
    let next = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    const max = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    if (next > max) next = max;
    document.getElementById('flMonthInput').value = next;
    flLoad(next);
}

// `fresh` skips the 2-minute server cache — used right after creating a claim
// here, so the money and the "N to approve" counts move immediately.
function flLoad(month, fresh) {
    flMonth = month;
    flCloseDetail();
    document.getElementById('flBody').innerHTML = '<tr><td colspan="12" class="fl-empty">Loading…</td></tr>';

    fetch(FL_BASE + '?month=' + encodeURIComponent(month) + (fresh ? '&fresh=1' : ''))
        .then(r => r.status === 403 ? Promise.reject(new Error('403')) : r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flData = res;
            // The ⚙️ Types button belongs to whoever may change service schedules.
            const tb = document.getElementById('flTypesBtn');
            if (tb) tb.style.display = res.can_manage_types ? '' : 'none';
            flRenderTable(res);
            flRenderVerdict(res.totals);
            flRenderNotes(res);
        })
        .catch(err => {
            const msg = err.message === '403'
                ? 'You do not have permission to see fleet costs.'
                : 'Could not load this month. Please try again.';
            document.getElementById('flBody').innerHTML =
                '<tr><td colspan="12" class="fl-empty">' + msg + '</td></tr>';
            document.getElementById('flVerdict').style.display = 'none';
            document.getElementById('flNotes').style.display = 'none';
        });
}

function flRenderTable(res) {
    const rows = res.riders || [];
    if (!rows.length) {
        document.getElementById('flBody').innerHTML =
            '<tr><td colspan="12" class="fl-empty">No fuel or distance recorded this month.</td></tr>';
        document.getElementById('flHeadline').innerHTML = '';
        return;
    }

    document.getElementById('flBody').innerHTML = rows.map(r => {
        const flags = [];
        // New requests waiting on someone — the reason to open this rider today.
        if (r.pending_count) flags.push('<span class="fl-pill fl-due" title="Requests waiting for approval">⏳ ' + r.pending_count + ' to approve</span>');
        if (r.dupe_flags) flags.push('<span class="fl-pill fl-warn" title="Possible duplicate claims">⚠ ' + r.dupe_flags + '</span>');
        if (r.early_service_count) flags.push('<span class="fl-pill fl-warn" title="Regular service done before the schedule was up">⏱ ' + r.early_service_count + ' early</span>');
        if (r.no_meter_days) flags.push('<span class="fl-pill fl-na" title="Days worked with no usable meter reading">' + r.no_meter_days + ' no-meter</span>');
        // Checked in on a past day and never checked out. Kept as "in progress" on
        // purpose — the team has to go and close it — but it must be VISIBLE, or a
        // day with deliveries and no end meter just disappears from every count.
        if (r.open_days) flags.push('<span class="fl-pill fl-warn" title="Checked in on a past day and never checked out — still open. Someone needs to close the day so its kilometres can be counted.">🔓 ' + r.open_days + ' still open</span>');
        if (r.bike === 'unknown') flags.push('<span class="fl-pill fl-unknown" title="No rider profile — cannot classify the bike">unclassified</span>');

        return '<tr onclick="flSelectRider(' + r.user_id + ')" id="flRow' + r.user_id + '">' +
            '<td class="fl-name">' + flEsc(r.name) + '</td>' +
            '<td>' + flBikePill(r) + '</td>' +
            '<td class="num">' + flNum(r.work_km) + '</td>' +
            '<td class="num">' + (r.offduty_km === null ? '<span class="fl-muted">—</span>' : flNum(r.offduty_km)) +
              // Km inside a stretch that contains a worked-but-unmetered day. Shown
              // right here so the commute figure is never read as including them.
              // ⚠ `unaccounted_km` is the PURE figure; `unattributed_km` also carries
              // shared + transit for older mobile builds — never show that one here
              // or the same kilometres appear in two columns.
              (r.unaccounted_km > 0
                ? '<div class="fl-maintsplit" style="color:#b45309;" title="Kilometres across a stretch that contains a day he worked with no usable meter — part work, part commute, and impossible to split. Not counted as either.">+' + flNum(r.unaccounted_km) + ' unaccounted</div>'
                : '') + '</td>' +
            // ⭐ Shared / transit — named, and charged to no one.
            '<td class="num">' + flSharedCell(r) + '</td>' +
            '<td class="num fl-strong">' + (r.fuelled_km > 0 ? flNum(r.fuelled_km) : '<span class="fl-muted">—</span>') + '</td>' +
            '<td class="num">' + flRs(r.fuel_rs) + (r.fuel_pending_rs > 0 ? ' <span class="fl-muted" title="Pending approval">+' + flRs(r.fuel_pending_rs) + '</span>' : '') + '</td>' +
            '<td class="num fl-strong">' + (r.rs_per_fuelled_km === null || r.rs_per_fuelled_km === undefined ? '<span class="fl-muted">—</span>' : r.rs_per_fuelled_km.toFixed(2)) + '</td>' +
            // Maintenance split by what was done — a repair bill and a scheduled
            // service are different stories and shouldn't sit in one number.
            '<td class="num">' + (r.maint_rs > 0
                ? flRs(r.maint_rs) + '<div class="fl-maintsplit">' +
                  (r.maint_regular_rs > 0 ? '🛢️ ' + flNum(r.maint_regular_rs) : '') +
                  (r.maint_repair_rs > 0 ? (r.maint_regular_rs > 0 ? ' · ' : '') + '🔧 ' + flNum(r.maint_repair_rs) : '') +
                  (r.maint_other_rs > 0 ? ' · 🔩 ' + flNum(r.maint_other_rs) : '') + '</div>'
                : '<span class="fl-muted">—</span>') + '</td>' +
            // Same denominator as the Rs/km beside it — see rs_per_fuelled_km_all.
            '<td class="num fl-strong">' + (r.rs_per_fuelled_km_all === null || r.rs_per_fuelled_km_all === undefined ? '<span class="fl-muted">—</span>' : r.rs_per_fuelled_km_all.toFixed(2)) + '</td>' +
            '<td>' + flServicePill(r.service) + '</td>' +
            '<td>' + flags.join(' ') + '</td></tr>';
    }).join('');

    const t = res.totals;
    const pending = rows.reduce((s, r) => s + (r.pending_count || 0), 0);
    document.getElementById('flHeadline').innerHTML =
        'Fuel <b>Rs ' + flNum(t.fuel_rs) + '</b> · Maintenance <b>Rs ' + flNum(t.maint_rs) + '</b>' +
        (pending ? ' · <b>⏳ ' + pending + '</b> waiting for approval' : '') +
        (t.dupe_flags ? ' · <b>⚠ ' + t.dupe_flags + '</b> possible duplicate claims' : '');
}

/**
 * ⭐⭐ THE MACHINE, NOT JUST THE KIND OF MACHINE (Aug-2026).
 *
 * This used to render a bare 🏢/👤 from a checkbox on the rider's profile, so a
 * manager could not tell WHICH bike anyone was on — the single biggest gap on this
 * screen. Now it names the plate he holds right now, keeps the company/own colour
 * for the at-a-glance read, and says "+N" when the month touched more machines.
 *
 * ⚠ Riders the registry has never tracked have no plate to show and fall back to
 *   the original pill exactly, so nothing regresses for them.
 */
function flBikePill(r) {
    const b = (typeof r === 'string') ? r : r.bike;
    const label = (typeof r === 'string') ? null : r.vehicle_label;
    const cls = b === 'company' ? 'fl-company' : (b === 'own' ? 'fl-own' : 'fl-unknown');

    if (!label) {
        if (b === 'company') return '<span class="fl-pill fl-company">🏢 company</span>';
        if (b === 'own') return '<span class="fl-pill fl-own">👤 own</span>';
        return '<span class="fl-pill fl-unknown">❓ unknown</span>';
    }

    // holds_now === false: he rode it this month but has since handed it back, so
    // the plate is history. Saying so beats implying he is still on it.
    const returned = r.holds_now === false;
    const icon = b === 'company' ? '🏍' : '👤';
    let out = '<span class="fl-pill ' + cls + '"' +
        (returned ? ' title="He rode this bike this month but does not hold it now"' : '') +
        '>' + icon + ' ' + flEsc(label) + (returned ? ' ·&nbsp;handed back' : '') + '</span>';

    const extra = (r.machine_count || 0) - 1;
    if (extra > 0) {
        const others = (r.machines || []).filter(m => m.label !== label)
            .map(m => m.label + ' (' + flNum(m.km_with_him) + ' km)').join(', ');
        out += ' <span class="fl-pill fl-na" title="Also this month: ' + flEsc(others) + '">+' + extra + '</span>';
    }
    return out;
}

/**
 * Kilometres that belong to nobody. Two different stories, so two lines:
 * the handover DAY (shared, and we name who with) and the bike's own travel
 * between two riders (transit).
 */
function flSharedCell(r) {
    const bits = [];
    if (r.shared_km) {
        bits.push('<span class="fl-pill fl-shared" title="Days this bike changed hands: one rider took the morning reading, the other the evening one. The distance is real, the split is not knowable — so it is charged to neither of them.">🔁 ' +
            flNum(r.shared_km) + ' shared</span>');
    }
    if (r.transfer_km) {
        bits.push('<div class="fl-maintsplit" style="color:#0f766e;" title="The bike travelling between two riders. Nobody\'s personal usage.">+' +
            flNum(r.transfer_km) + ' in transit</div>');
    }
    if (!bits.length) return '<span class="fl-muted">—</span>';
    return bits.join('');
}

function flServicePill(s) {
    if (!s || s.state === 'unknown') {
        return '<span class="fl-pill fl-na" title="No last-service reading recorded yet">not set</span>';
    }
    if (s.state === 'overdue') {
        return '<span class="fl-pill fl-over">🔴 overdue ' + flNum(Math.abs(s.due_in_km)) + ' km</span>';
    }
    if (s.state === 'due_soon') {
        return '<span class="fl-pill fl-due">🟡 due in ' + flNum(s.due_in_km) + ' km</span>';
    }
    return '<span class="fl-pill fl-ok">🟢 ' + flNum(s.due_in_km) + ' km left</span>';
}

/**
 * The comparison, as a compact grid rather than a paragraph (owner, Jul-28).
 * Two rows, three numbers each: kilometres ridden, fuel per km, and everything-in
 * per km. The all-in column is the one to decide on — it carries the maintenance.
 */
function flRenderVerdict(t) {
    const el = document.getElementById('flVerdict');
    const c = t.company, o = t.own;
    if (!c || !o || !c.rs_per_fuelled_km || !o.rs_per_fuelled_km) {
        el.style.display = 'none';
        return;
    }

    // Decided on ALL-IN cost per km ridden — fuel alone ignores that a company
    // bike also brings its own repair bill.
    const ca = c.rs_per_fuelled_km_all, oa = o.rs_per_fuelled_km_all;
    const diff = ca - oa;
    const pct = oa > 0 ? Math.abs(diff) / oa * 100 : 0;
    const close = pct < 8;

    const row = (icon, label, riders, km, fuelRate, allRate, winner) =>
        '<tr>' +
        '<td>' + icon + ' ' + label + ' <span class="dim">(' + riders + ')</span></td>' +
        '<td>' + flNum(km) + '</td>' +
        '<td>' + (fuelRate === null ? '—' : fuelRate.toFixed(2)) + '</td>' +
        '<td class="big"' + (winner ? '' : ' style="opacity:.6;"') + '>' + (allRate === null ? '—' : allRate.toFixed(2)) + '</td>' +
        '</tr>';

    let s = '<table class="fl-cmp">' +
        '<tr><th>Bike</th><th>Km ridden</th><th>Fuel Rs/km</th><th>All-in Rs/km</th></tr>' +
        row('🏢', 'Company', c.riders, c.fuelled_km, c.rs_per_fuelled_km, ca, close || diff < 0) +
        row('👤', 'Own', o.riders, o.fuelled_km, o.rs_per_fuelled_km, oa, close || diff > 0) +
        '</table>';

    s += '<div class="fl-cmpwin">' + (close
        ? 'Within ' + pct.toFixed(0) + '% — too close to call this month.'
        : (diff > 0
            ? 'Own bikes cost Rs ' + Math.abs(diff).toFixed(2) + '/km less, all in (' + pct.toFixed(0) + '%).'
            : 'Company bikes cost Rs ' + Math.abs(diff).toFixed(2) + '/km less, all in (' + pct.toFixed(0) + '%).')) +
        '</div>';

    // ONE short line, not a paragraph. It still has to be said: a company bike's
    // km include the commute we fuel, and its all-in still leaves out the machine.
    s += '<div class="fl-cmpfoot">Company km include the commute you fuel; own-bike riders fund their own. ' +
         'All-in adds maintenance, still not the bike itself.</div>';

    el.className = 'fl-verdict' + (close ? ' tie' : '');
    el.innerHTML = s;
    el.style.display = 'block';
}

function flRenderNotes(res) {
    const el = document.getElementById('flNotes');
    const t = res.totals;
    const notes = [];
    if (t.unattributed_rs > 0) {
        notes.push('<b>Rs ' + flNum(t.unattributed_rs) + '</b> of fuel and maintenance could not be tied to any ' +
            'kilometres (no usable meter readings)' +
            (t.unattributed_who && t.unattributed_who.length ? ': ' + t.unattributed_who.map(flEsc).join(', ') : '') +
            '. It is excluded from every Rs/km figure above.');
    }
    // Moved out of the comparison box to keep that box to figures only. Still has
    // to be stated: these km are neither work nor commute, so no rate claims them.
    // ⭐ Aug-2026: this used to be one lump. Most of it turned out to be handover
    //   days and bikes in transit — km with a perfectly good explanation — so the
    //   two are now counted and worded separately instead of reading as suspicion.
    // ⚠ From the TOTALS, not by summing rider rows — a shared leg is named on both
    //   riders on purpose, so adding the rows up counts every such kilometre twice.
    const shared = (t.shared_km || 0) + (t.transfer_km || 0);
    const unattKm = (t.company && t.company.unattributed_km) || 0;
    const pureUnacc = Math.max(0, unattKm - shared * 2);
    if (shared > 0) {
        notes.push('<b>' + flNum(shared) + ' km</b> ran on days a bike changed hands, or while it was ' +
            'travelling between riders. Real distance with a known reason — charged to nobody.');
    }
    if (pureUnacc > 0) {
        notes.push('<b>' + flNum(pureUnacc) + ' km</b> ran across stretches containing a day worked with no meter — ' +
            'part work, part commute, counted as neither.');
    }
    if (!notes.length) { el.style.display = 'none'; return; }
    el.innerHTML = notes.join('<br>');
    el.style.display = 'block';
}

// =============================================
// RIDER DETAIL — datewise
// =============================================
function flSelectRider(uid) {
    flSelected = uid;
    document.querySelectorAll('.fl-table tbody tr').forEach(tr => tr.classList.remove('sel'));
    const row = document.getElementById('flRow' + uid);
    if (row) row.classList.add('sel');

    const el = document.getElementById('flDetail');
    el.style.display = 'block';
    el.innerHTML = '<div class="fl-dhead"><h4>Loading…</h4></div>';

    // Which machine he was on each day rides along with the costs. Fetched in
    // parallel and deliberately FAILURE-TOLERANT: an older server (or one where
    // batch 13 has not been run) simply returns nothing and the day rows render
    // exactly as they always did.
    Promise.all([
        fetch(FL_BASE + '/rider?month=' + encodeURIComponent(flMonth) + '&rider_id=' + uid)
            .then(r => r.json()),
        fetch(FLV_BASE + '/rider-days?rider_id=' + uid + '&month=' + encodeURIComponent(flMonth))
            .then(r => r.ok ? r.json() : {days: []}).catch(() => ({days: []})),
    ])
        .then(([res, vd]) => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flApproval = res.approval || null;
            flCanManageService = !!res.can_manage_service;
            flDefaultInterval = res.default_interval_km || 0;
            // Kept so the edit form can find a claim by id without another round
            // trip — the drawer already has every field it needs.
            flRider = res.rider || null;

            flvDayMap = {};
            flvDaysCanManage = !!(vd && vd.can_manage);
            ((vd && vd.days) || []).forEach(d => { flvDayMap[d.date] = d; });

            flRenderDetail(res.rider);
        })
        .catch(() => {
            el.innerHTML = '<div class="fl-dhead"><h4>Could not load this rider</h4>' +
                '<button class="fl-dclose" onclick="flCloseDetail()">&times;</button></div>';
        });
}

function flCloseDetail() {
    flSelected = null;
    const el = document.getElementById('flDetail');
    if (el) el.style.display = 'none';
    document.querySelectorAll('.fl-table tbody tr').forEach(tr => tr.classList.remove('sel'));
}

function flRenderDetail(r) {
    const days = (r.days || []).map(d => {
        const claims = (d.claims || []).map(c => flClaimRow(c)).join('');
        let km = '';
        if (d.work_km !== null && d.work_km !== undefined) {
            km = '<b>' + flNum(d.meter_start) + ' → ' + flNum(d.meter_end) + '</b> · <b>' + d.work_km + ' km</b>';
            if (d.offduty_km !== null && d.offduty_km > 0) {
                km += d.offduty_since
                    ? ' · <span title="Measured from the last usable reading, not yesterday">+' +
                      d.offduty_km + ' km off-duty since ' + flDate(d.offduty_since) + '</span>'
                    : ' · <span title="Ridden since the previous day\'s close">+' + d.offduty_km + ' km off-duty</span>';
            }
            if (d.incl_ride_home) {
                km += ' <span class="fl-muted" title="On duty runs to the meter-out at home">🏠 to home</span>';
            }
        } else if (d.handover) {
            // ⭐ A handover day is NOT a missing reading — he recorded his end of it.
            // ⚠ LABELS ARE ENGLISH (owner ruling): "meter start" / "meter end". Only
            //   the explanations are Roman Urdu, and the same two words are used on
            //   the machine's day cards so both views speak one language.
            km = '<span title="His half of a handover day — the other rider recorded the other end. Not a missed reading.">'
                + (d.meter_start !== null && d.meter_start !== undefined
                    ? 'meter start <b>' + flNum(d.meter_start) + '</b>'
                    : (d.meter_end !== null && d.meter_end !== undefined
                        ? 'meter end <b>' + flNum(d.meter_end) + '</b>'
                        : '<span class="fl-muted">bike change day</span>'))
                + '</span>';
        } else {
            // Say WHY. Leave/absent are states, not failures — only a day he
            // actually worked can be "missing" a reading.
            const cls = d.status === 'missing' ? 'fl-daymissing' : 'fl-muted';
            km = '<span class="' + cls + '">' + (FL_DAY_TEXT[d.detail] || FL_DAY_TEXT[d.status] || 'no meter reading') + '</span>';
        }

        // Kilometres nobody is charged for — short on screen, full in the tooltip.
        let extra = '';
        if (d.shared_km) {
            extra += '<div class="fl-handover" title="Bike changed hands this day: one rider took the morning reading, the other the evening one. The distance is real but cannot be split, so it is charged to neither of them.">' +
                '🔁 <b>' + flNum(d.shared_km) + ' km</b> shared' +
                (d.shared_with ? ' · ' + flEsc(d.shared_with) + ' ke saath' : '') +
                ' · <span class="fl-muted">kisi ke naam nahi</span></div>';
        }
        if (d.transfer_km) {
            extra += '<div class="fl-handover" title="The bike travelling between two riders — nobody\'s personal usage.">' +
                '🔁 <b>' + flNum(d.transfer_km) + ' km</b> transit' +
                (d.transfer_with ? ' · ' + flEsc(d.transfer_with) + ' ke saath' : '') +
                ' · <span class="fl-muted">kisi ke naam nahi</span></div>';
        }
        if (d.unattributed_km) {
            extra += '<div class="fl-handover" style="background:#fdf3e0;border-left-color:#b45309;" ' +
                'title="This stretch spans a day worked with no usable reading, so it cannot be split into work and commute. Counted as neither.">' +
                '⚠ <b>' + flNum(d.unattributed_km) + ' km</b> unaccounted · ' +
                '<span class="fl-muted">beech mein aik din meter ke baghair</span></div>';
        }

        // Whose hand wrote the reading down. ⚠ The MACHINE is deliberately NOT
        // repeated here — flvDayChip above already owns the per-day vehicle label
        // (and the manager-override affordance with it), so adding a second chip
        // printed the plate twice on every row.
        const marks = [];
        if (d.start_source === 'manager') {
            marks.push('<span class="fl-pill fl-warn" title="The morning reading was entered by a manager, not by the rider">✎ manager</span>');
        }

        return '<div class="fl-day"><div class="fl-dayhead">' +
            '<span class="fl-daydate">' + flDate(d.date) + flvDayChip(r.user_id, d.date) +
              (marks.length ? ' ' + marks.join(' ') : '') + '</span>' +
            '<span class="fl-daykm">' + km + '</span></div>' + extra + claims + '</div>';
    }).join('');

    // ⭐ HIS MACHINES THIS MONTH — the strip that answers "what was he driving?"
    //   without leaving the row. Each one opens that bike's own profile, so the
    //   machine's full story stays in one place and is never duplicated here.
    let machinesHtml = '';
    if ((r.machines || []).length) {
        machinesHtml = '<h5 style="margin-top:2px;">His machines this month</h5>' +
            r.machines.map(m => {
                const rate = (m.rs_per_km !== null && m.rs_per_km !== undefined)
                    ? 'Rs ' + m.rs_per_km.toFixed(2) + '/km' : '<span class="fl-muted">no rate</span>';
                return '<div class="fl-mrow">' +
                    '<span class="fl-pill ' + (m.is_company ? 'fl-company' : 'fl-own') + '">' +
                      (m.is_company ? '🏍 ' : '👤 ') + flEsc(m.label) + '</span>' +
                    '<span class="fl-mkm">' + flNum(m.km_with_him) + ' km</span>' +
                    '<span class="fl-muted">' + m.days + ' day' + (m.days === 1 ? '' : 's') +
                      (m.shared_km ? ' · 🔁 ' + flNum(m.shared_km) + ' shared' : '') + '</span>' +
                    '<span>' + (m.fuel_rs > 0 ? 'Rs ' + flNum(m.fuel_rs) + ' fuel' : '<span class="fl-muted">no fuel</span>') + '</span>' +
                    '<span>' + rate + '</span>' +
                    (m.reconciles === false
                      ? '<span class="fl-pill fl-warn" title="This bike\'s odometer chain has a hole this month — readings exist that nobody recorded a handover for. His duty kilometres still stand; the stretches between days do not.">⚠ chain incomplete</span>'
                      : '') +
                    '<span class="fl-mlink" onclick="flOpenVehicle(' + m.vehicle_id + ')">open ' + flEsc(m.label) + ' ▸</span>' +
                    '</div>';
            }).join('');
    }

    const svc = r.service;
    // ⚠ These figures are ONE job's story — the most urgent scheduled one, which the
    //   server names in due_type_name — so the heading names it too. An unnamed
    //   "last done" reads as the bike's most recent visit, which can be a different,
    //   larger job on a slower clock.
    let svcHtml = '<h5>Service' + (svc && svc.due_type_name ? ' — ' + flEsc(svc.due_type_name) : '') + '</h5>';
    if (svc && svc.state !== 'unknown') {
        svcHtml += '<div class="fl-svc"><span>Status</span><span>' + flServicePill(svc) + '</span></div>' +
            '<div class="fl-svc"><span>Since last service</span><span>' + flNum(svc.since_km) + ' km</span></div>' +
            '<div class="fl-svc"><span>Interval</span><span>' + flNum(svc.interval_km) + ' km</span></div>' +
            '<div class="fl-svc"><span>Last done</span><span>' + (svc.last_service_at ? flDate(svc.last_service_at) : '—') + '</span></div>';
    } else {
        svcHtml += '<div class="fl-svc"><span>Status</span><span>' + flServicePill(svc) + '</span></div>' +
            '<div style="font-size:12px;color:#6b7280;margin:6px 0 8px;">Record the odometer at the last oil change to start tracking.</div>';
    }
    // Schedule controls only for someone who may CHANGE it — reading the running
    // costs never implies moving when a bike falls due.
    if (flCanManageService) {
        svcHtml += '<div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">' +
            // Three DISTINCT actions, matching mobile. Recording a service and
            // changing the schedule are different things and must not share a
            // button — one resets the due clock, the other only says how often.
            '<button class="fl-btn" onclick="flMarkServiced(' + r.user_id + ',' +
            (svc && svc.current_meter ? svc.current_meter : 0) + ')">🛢️ Record service</button>' +
            // ⚠ The STORED override, not the derived interval — see the note in
            //   shape(). Pre-filling the due job's schedule here would let a manager
            //   "confirm" a value he never set.
            '<button class="fl-btn" onclick="flSetBikeInterval(' + r.user_id + ',' +
            (svc && svc.interval_override ? svc.interval_override : 0) + ')">⚙️ This bike\'s schedule</button>' +
            '<button class="fl-btn" onclick="flSetDefaultInterval()">🏢 Company default (' + flNum(flDefaultInterval) + ' km)</button>' +
            '</div>' +
            // The rider's own maintenance request is the normal input — it carries
            // the bill and the photo, and approving it resets the clock by itself.
            // Saying so stops a manager double-recording work already filed.
            '<div style="font-size:11px;color:#9ca3af;margin-top:7px;line-height:1.45;">' +
            'Riders normally record a service by filing a Maintenance request with the meter reading — ' +
            'approving it resets this automatically. Use “Record service” only for work filed no other way.</div>';
    }

    // 🔧 Per-type schedule — each job on its own clock. This is what the manager
    // asked for: "Oil Change every 1,200" and "Brake Shoe every 10,000" are not
    // the same countdown, and one combined number hid that.
    const sched = r.service_schedule || [];
    if (sched.length) {
        svcHtml += '<h5 style="margin-top:14px;">Service schedule</h5>' + sched.map(s => {
            const tone = s.state === 'overdue' ? '#b91c1c'
                       : (s.state === 'due_soon' ? '#b45309'
                       : (s.state === 'ok' ? '#15803d' : '#9ca3af'));
            const right = s.due_in_km === null
                ? '<span style="color:#9ca3af;">never recorded</span>'
                : (s.due_in_km < 0
                    ? '<b style="color:' + tone + '">' + flNum(Math.abs(s.due_in_km)) + ' km overdue</b>'
                    : '<b style="color:' + tone + '">' + flNum(s.due_in_km) + ' km left</b>');
            return '<div class="fl-svc"><span>' + flEsc(s.name) +
                   ' <span style="color:#9ca3af;">· every ' + flNum(s.interval_km) + ' km</span></span>' +
                   '<span>' + right +
                   (s.last_meter !== null ? ' <span style="color:#9ca3af;">· last ' + flNum(s.last_meter) + ' km</span>' : '') +
                   '</span></div>';
        }).join('');
    }

    // Where this month's maintenance money actually went.
    const byType = r.maint_by_type || [];
    if (byType.length) {
        svcHtml += '<h5 style="margin-top:14px;">This month by type</h5>' + byType.map(x =>
            '<div class="fl-svc"><span>' + flEsc(x.label) +
            ' <span style="color:#9ca3af;">· ' + x.n + ' claim' + (x.n === 1 ? '' : 's') +
            (x.pending_n ? ', ' + x.pending_n + ' pending' : '') + '</span></span>' +
            '<span>Rs ' + flNum(x.total) + '</span></div>').join('');
    }

    const hist = r.service_history || [];   // approved + pending, filtered server-side
    if (hist.length) {
        svcHtml += '<h5 style="margin-top:14px;">Past services</h5>' + hist.map(h =>
            '<div class="fl-svc"><span>' + flDate(h.date) + (h.type ? ' · ' + flServiceLabel(h.type) : '') +
            (h.status === 'pending' ? ' <span class="fl-pill fl-due">⏳</span>' : '') + '</span>' +
            '<span>Rs ' + flNum(h.amount) + (h.photo ? ' <a href="#" onclick="flPhoto(\'' + h.photo + '\');return false;">📷</a>' : '') + '</span></div>'
        ).join('');
    }

    // The header pill uses the TABLE row, so it names the same machine the row does.
    const headRow = ((flData && flData.riders) || []).find(x => x.user_id === r.user_id) || r;

    document.getElementById('flDetail').innerHTML =
        '<div class="fl-dhead"><h4>' + flEsc(r.name) + '</h4>' + flBikePill(headRow) +
        '<span style="font-size:12px;color:#6b7280;">day by day · ' + flMonthLabel(r.month) + '</span>' +
        '<button class="fl-dclose" onclick="flCloseDetail()" title="Close">&times;</button></div>' +
        '<div class="fl-dbody"><div class="fl-days">' + flKmSummary(r) + machinesHtml +
        (days || '<div class="fl-empty">Nothing recorded this month.</div>') +
        '</div><div class="fl-side">' + svcHtml + '</div></div>';
}

/**
 * ⭐ RIDER → MACHINE. The rider view deliberately shows only his SLICE of a bike;
 *    the bike's whole story (odometer, service, everyone who rode it) lives in the
 *    Vehicles view. Rather than duplicate it here, we take the manager there.
 */
function flOpenVehicle(vehicleId) {
    if (!vehicleId) return;
    flSetMode('vehicles');
    // The grid may still be loading on first switch; wait for it, then open.
    let tries = 0;
    (function open() {
        if (typeof flvOpen === 'function' && flvData && (flvData.vehicles || []).length) {
            flvOpen(vehicleId);
        } else if (tries++ < 40) {
            setTimeout(open, 100);
        }
    })();
}

/**
 * The month's distance, sitting directly above the days that produced it, so a
 * total can always be traced to the readings behind it. Read from the same month
 * row as the table, so the two can never disagree.
 */
function flKmSummary(r) {
    const row = ((flData && flData.riders) || []).find(x => x.user_id === r.user_id);
    if (!row) return '';
    const counted = (r.days || []).filter(d => d.work_km !== null && d.work_km !== undefined).length;

    let cells = '<div class="fl-kmcell"><b>' + flNum(row.work_km) + '</b><span>on duty</span></div>';
    if (row.offduty_km !== null && row.offduty_km !== undefined) {
        // Tap to see it night by night — this is the only distance outside the
        // shift, so it is the number a manager actually wants to interrogate.
        cells += '<div class="fl-kmcell off tap" onclick="flToggleOffNights()" title="Show each night">' +
                 '<b>' + flNum(row.offduty_km) + ' ›</b><span>off duty</span></div>';
    }
    // Kilometres that belong to nobody get their own cells — putting them inside
    // "off duty" is exactly the mistake this project exists to undo.
    if (row.shared_km) {
        cells += '<div class="fl-kmcell" style="background:#e6f7f3;" title="Days the bike changed hands. Real distance, unsplittable — charged to neither rider.">' +
                 '<b style="color:#0f766e;">' + flNum(row.shared_km) + '</b><span>🔁 shared</span></div>';
    }
    if (row.transfer_km) {
        cells += '<div class="fl-kmcell" style="background:#e6f7f3;" title="The bike travelling between riders.">' +
                 '<b style="color:#0f766e;">' + flNum(row.transfer_km) + '</b><span>🔁 in transit</span></div>';
    }
    if (row.total_km !== null && row.total_km !== undefined) {
        cells += '<div class="fl-kmcell"><b>' + flNum(row.total_km) + '</b><span>total</span></div>';
    }
    cells += '<div class="fl-kmcell"><b>' + counted + '</b><span>days counted</span></div>';

    let notes = '';
    if (row.chain_ok === false) {
        notes += '<div class="fl-kmnote">⚠ One of these machines has a gap in its odometer history this month — ' +
                 'readings exist that no handover was recorded for. His duty kilometres still stand; ' +
                 'the stretches between days cannot be trusted until the days are corrected.</div>';
    }
    if (row.no_meter_days) {
        notes += '<div class="fl-kmnote">⚠ ' + row.no_meter_days + ' day' + (row.no_meter_days === 1 ? '' : 's') +
                 ' he worked without a usable meter reading — those kilometres are not in the totals above.</div>';
    }
    if (row.incl_ride_home_days) {
        // By design: the ride home counts as shift. Stated plainly, not as a caveat.
        notes += '<div class="fl-kmnote dim">On duty runs to the meter-out at home on ' +
                 row.incl_ride_home_days + ' day' + (row.incl_ride_home_days === 1 ? '' : 's') +
                 '. Off duty is the stretch from there to the next morning.</div>';
    }

    // Each off-duty stretch: meter-out at home → next morning's meter-in.
    let offList = '';
    (r.off_nights || []).forEach(n => {
        offList += '<div class="fl-offrow"><span>' + flDate(n.date) +
            (n.since ? ' <span style="color:#9ca3af;">(since ' + flDate(n.since) + ')</span>' : '') +
            ' &nbsp;' + (n.from !== null ? flNum(n.from) + ' → ' + flNum(n.to) : '') +
            (n.vehicle_label ? ' <span style="color:#9ca3af;">on ' + flEsc(n.vehicle_label) + '</span>' : '') +
            '</span><span>' + flNum(n.km) + ' km</span></div>';
    });
    if (offList) {
        offList = '<div class="fl-offlist" id="flOffNights" style="display:none;">' +
            '<div style="font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">' +
            'Off duty — meter-out at home → next morning</div>' + offList + '</div>';
    }

    return '<div class="fl-kmbox"><div class="fl-kmtitle">Distance this month</div>' +
           '<div class="fl-kmrow">' + cells + '</div>' + notes + offList + '</div>';
}

function flToggleOffNights() {
    const el = document.getElementById('flOffNights');
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function flClaimRow(c) {
    const flagText = {
        double_tap: 'same amount filed minutes apart — likely a double tap',
        flat_on_metered_day: 'cash claim on a day the meter already paid for',
        second_same_day: 'second cash claim of the day'
    };
    const photo = c.photo
        ? '<img class="fl-thumb" src="' + c.photo + '" alt="Receipt" onclick="flPhoto(\'' + c.photo + '\')">'
        : '<div class="fl-nophoto" title="No photo attached">✕</div>';

    let mid = '<span class="fl-amt">Rs ' + flNum(c.amount) + '</span>';
    // Show the manager's own category name ("Brake Shoe") when the claim carries
    // one; older claims fall back to the generic Regular/Repair wording.
    mid += ' <span class="fl-pill ' + (c.kind === 'fuel' ? 'fl-own' : 'fl-company') + '">' +
           (c.kind === 'fuel' ? '⛽ fuel'
                              : '🔧 ' + flEsc(c.maintenance_type || flServiceLabel(c.service_type))) + '</span>';
    if (c.source === 'meter') {
        mid += ' <span class="fl-muted">' + c.meter_distance + ' km × ' + c.petrol_rate + '</span>';
    } else {
        mid += ' <span class="fl-muted">cash claim</span>';
    }
    if (c.meter_at_fill) mid += ' <span class="fl-muted">· meter ' + flNum(c.meter_at_fill) + '</span>';
    // The approver's number: how far the bike went on the PREVIOUS tank.
    if (c.km_since_fill) {
        mid += ' <span class="fl-pill fl-company" title="Kilometres since the previous fuel fill">▲ ' + flNum(c.km_since_fill) + ' km since last fill</span>';
    } else if (c.km_since_fill_odd) {
        mid += ' <span class="fl-pill fl-warn" title="This meter reading and the previous fill\'s reading don\'t add up — typo or a different bike">⚠ meter vs last fill doesn\'t add up</span>';
    }
    if (c.litres) mid += ' <span class="fl-muted">· ' + c.litres + ' L</span>';
    // Every claim states its money status plainly. Only approved and pending
    // exist here — rejected/cancelled are filtered out server-side.
    mid += c.status === 'approved'
        ? ' <span class="fl-pill fl-ok">✓ approved</span>'
        : ' <span class="fl-pill fl-due">⏳ pending</span>';
    if (c.flag) mid += ' <span class="fl-pill fl-warn" title="' + (flagText[c.flag] || '') + '">⚠ ' + (flagText[c.flag] || c.flag) + '</span>';
    // Serviced before the schedule was up — money spent sooner than needed, or a
    // bike with a problem. Either way the approver should see it.
    if (c.service_early_by) {
        mid += ' <span class="fl-pill fl-warn" title="' + flNum(c.km_since_service) + ' km since the last service; schedule is ' +
               flNum(c.service_interval) + ' km">⏱ serviced ' + flNum(c.service_early_by) + ' km early</span>';
    } else if (c.service_late_by) {
        mid += ' <span class="fl-pill fl-warn" title="' + flNum(c.km_since_service) + ' km since the last service; schedule is ' +
               flNum(c.service_interval) + ' km">⏱ serviced ' + flNum(c.service_late_by) + ' km overdue</span>';
    } else if (c.km_since_service) {
        mid += ' <span class="fl-pill fl-company">▲ ' + flNum(c.km_since_service) + ' km since last service</span>';
    }
    // Still waiting for approval, so the clock hasn't reset — the bike is running
    // past due right now. This is the same number the chip at the top shows, put
    // where the decision is actually made.
    if (c.overdue_now_km) {
        mid += ' <span class="fl-pill fl-warn" title="The bike has run ' + flNum(c.overdue_now_km) +
               ' km past its service schedule and this request has not been approved yet">🔴 bike is ' +
               flNum(c.overdue_now_km) + ' km overdue</span>';
    }
    // Frozen at approval, so it survives the clock reset that follows.
    if (c.service_due_km_at_approval !== null && c.service_due_km_at_approval !== undefined) {
        const d = c.service_due_km_at_approval;
        if (d < 0) mid += ' <span class="fl-pill fl-warn" title="Recorded when this was approved">🔴 done ' + flNum(-d) + ' km overdue</span>';
        else if (d > 25) mid += ' <span class="fl-pill fl-muted" title="Recorded when this was approved">⏱ done ' + flNum(d) + ' km before due</span>';
        else mid += ' <span class="fl-pill fl-ok" title="Recorded when this was approved">⏱ done on schedule</span>';
    }

    // Approve / reject right here — the whole point of this screen is that the
    // approver sees the month's context (duplicate flags, km, other claims that
    // day) at the moment of deciding. Posts to the SAME endpoint and payload as
    // the Daily Closing screen, including the payment source, so money is booked
    // identically no matter where it was approved from.
    let actions = '';
    if (c.status === 'pending' && flApproval && flApproval.can_approve
        && c.next_level && flApproval.levels.indexOf(c.next_level) !== -1) {
        // ⭐ Pre-select what the claim was FILED against, falling back to this
        // approver's own default. Until Aug-2026 this list always started on its
        // first option (NF Cash) and flApprove ALWAYS sent it — so a manager who
        // filed "paid from Online Bank" had it silently rebooked to NF Cash unless
        // the approver happened to touch the dropdown.
        const list = flApproval.accounts || [];
        const filed = list.some(a => a.id === c.filed_source_id) ? c.filed_source_id : null;
        const preferred = filed || (list.find(a => a.is_default) || {}).id;
        const accs = list.map(a =>
            '<option value="' + a.id + '"' + (a.id === preferred ? ' selected' : '') +
            ' data-online="' + (a.is_online ? '1' : '0') + '">' +
            flEsc(a.display_name || a.name) + '</option>').join('');
        actions =
            '<div class="fl-actions" id="flAct' + c.id + '">' +
            (accs ? '<select class="fl-src" id="flSrc' + c.id + '" title="Pay from"' +
                    ' data-filed="' + (filed || '') + '" data-bank="' + (c.filed_bank_id || '') + '">' + accs + '</select>' : '') +
            '<button class="fl-approve" onclick="flApprove(' + c.id + ',' + c.next_level + ')">✅ Approve</button>' +
            '<button class="fl-reject" onclick="flReject(' + c.id + ',' + c.next_level + ')">❌ Reject</button>' +
            // ✏️ Correct what the rider sent BEFORE approving it — wrong category,
            // wrong amount, wrong meter. Only while pending: once approved the
            // money is in the ledger and the service may have moved the clock.
            (flCanManageTypes()
                ? '<button class="fl-reject" style="background:#e0e7ff;color:#3730a3;border-color:#c7d2fe;" ' +
                  'onclick="flOpenEdit(' + c.id + ')">✏️ Edit</button>'
                : '') +
            '</div>';
    }

    // What the approver wrote — often the only record of WHAT the money bought
    // ("Tyre Puncture"). Lives on the approval row and reached no screen before.
    let notes = '';
    (c.approval_notes || []).forEach(n => {
        notes += '<div class="fl-appnote">💬 ' + flEsc(n.text) +
                 '<span> — ' + flEsc(n.by || 'approver') + '</span></div>';
    });
    // Who signed it off, and from which screen. Same money, different desk —
    // this is how you tell Shabib approving from Daily Closing apart from Qasim
    // approving here.
    (c.approval_actions || []).forEach(a => {
        notes += '<div class="fl-appwho">' + (a.status === 'rejected' ? '❌ Rejected' : '✅ Approved') +
                 (a.level ? ' (L' + a.level + ')' : '') +
                 ' by <b>' + flEsc(a.by || 'unknown') + '</b>' +
                 (a.source ? ' from ' + flEsc(a.source) : '') +
                 (a.at ? ' <span class="fl-muted">· ' + flEsc(String(a.at).slice(0, 16).replace('T', ' ')) + '</span>' : '') +
                 '</div>';
    });

    return '<div class="fl-claim' + (c.flag ? ' flagged' : '') + '" id="flClaim' + c.id + '">' +
           photo + '<div style="flex:1;">' + mid + notes + '</div>' + actions + '</div>';
}

// ---- approve / reject a pending claim ----
// Same endpoint, level and payload the Daily Closing screen uses. On success the
// row is replaced in place and the month totals are reloaded, because approving
// moves money from "pending" into the Rs/km figures above.
function flClaimAction(id, level, action, extra) {
    const box = document.getElementById('flAct' + id);
    if (box) box.innerHTML = '<span class="fl-muted">' + (action === 'approve' ? 'Approving…' : 'Rejecting…') + '</span>';

    const payload = Object.assign({level: level}, extra || {});
    fetch('/requests/' + id + '/' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        const row = document.getElementById('flClaim' + id);
        if (row) {
            row.style.background = action === 'approve' ? '#f0fdf4' : '#fef2f2';
            row.style.borderColor = action === 'approve' ? '#86efac' : '#fecaca';
            row.innerHTML = '<div style="padding:2px 4px; font-size:12.5px; font-weight:600; color:' +
                (action === 'approve' ? '#15803d' : '#b91c1c') + ';">' +
                (action === 'approve' ? '✅ Approved' : '❌ Rejected') + '</div>';
        }
        // totals and the rider row above are now stale
        flLoad(flMonth);
    })
    .catch(err => {
        alert(err.message || 'Could not complete that. Please try again.');
        if (flSelected) flSelectRider(flSelected);
    });
}

function flApprove(id, level) {
    if (!confirm('Approve this claim?')) return;
    const sel = document.getElementById('flSrc' + id);
    const extra = {comments: 'Approved from Fleet'};
    if (sel && sel.value) {
        extra.payment_source_account_id = parseInt(sel.value, 10);
        // An ONLINE source must say which bank, or the per-bank balances drift.
        // The bank the claim was filed with is reused when the source is unchanged;
        // if the approver switched TO an online source that had no bank, ask.
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.online === '1') {
            let bank = sel.dataset.bank ? parseInt(sel.dataset.bank, 10) : null;
            const unchanged = sel.dataset.filed && parseInt(sel.dataset.filed, 10) === parseInt(sel.value, 10);
            if (!bank || !unchanged) {
                bank = flAskBank(bank);
                if (bank === null) return;          // cancelled — do not approve blind
            }
            extra.receiving_account_id = bank;
        }
    }
    flClaimAction(id, level, 'approve', extra);
}

/**
 * Which of our banks did this leave from? A numbered prompt rather than a modal:
 * this strip is a dense table row and the list is short. Returns null if cancelled.
 */
function flAskBank(current) {
    const banks = (flData && flData.pay_banks) ? flData.pay_banks : [];
    if (!banks.length) return current || null;
    const lines = banks.map((b, i) => (i + 1) + '. ' + (b.short_code || b.name)).join('\n');
    const cur = banks.findIndex(b => b.id === current);
    const ans = prompt('This is an online payment — which bank did it leave from?\n\n' + lines,
                       cur >= 0 ? String(cur + 1) : '1');
    if (ans === null) return null;
    const idx = parseInt(ans, 10) - 1;
    if (isNaN(idx) || idx < 0 || idx >= banks.length) { alert('That is not one of the listed banks.'); return null; }
    return banks[idx].id;
}

function flReject(id, level) {
    const reason = window.prompt('Why is this being rejected? (the rider sees this)');
    if (reason === null) return;
    if (!String(reason).trim()) { alert('Please give a short reason.'); return; }
    // The reject endpoint requires `comments` — that string is what the rider is shown.
    flClaimAction(id, level, 'reject', {comments: reason.trim()});
}

/**
 * The company-wide interval — what every bike without its own schedule follows.
 * Separate from the per-bike setter because one edit here moves every such
 * bike's due date at once, so it says so before saving.
 */
function flSetDefaultInterval() {
    document.getElementById('flDefKm').value = flDefaultInterval || '';
    flDefError('');
    const box = document.getElementById('flDefOverrides');
    box.innerHTML = '<div style="font-size:12px;color:#9ca3af;">Checking which bikes have their own schedule…</div>';
    document.getElementById('flDefModal').style.display = 'flex';

    // Ask BEFORE showing the choice — a manager cannot decide about bikes he has
    // not been shown.
    fetch(FL_BASE + '/interval-overrides', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { box.innerHTML = ''; return; }
            flRenderDefaultOverrides(res);
        })
        .catch(() => { box.innerHTML = ''; });   // silence = "nothing special to warn about"
}

/**
 * ⭐ NAME THE EXCEPTIONS, THEN ASK (owner ask, Aug-16).
 *
 * The old prompt asserted "bikes with their own interval are unaffected" and stopped
 * there, so the manager never learned WHICH bikes would ignore his change. Here they
 * are listed with their own numbers, and the decision is an explicit, defaulted-safe
 * choice rather than a hidden behaviour.
 *
 * ⚠ Bikes whose override already equals the company value are shown greyed and are
 *   NOT counted as exceptions — "overriding" them changes nothing, and listing them
 *   as casualties would make the warning cry wolf.
 */
function flRenderDefaultOverrides(res) {
    const box = document.getElementById('flDefOverrides');
    const vs = (res.vehicles || []);
    const rs = (res.riders || []);
    if (!vs.length && !rs.length) {
        box.innerHTML = '<div style="font-size:12px;color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;'
            + 'border-radius:8px;padding:9px 11px;">✓ No bike has a schedule of its own — this applies to the whole fleet.</div>';
        return;
    }

    const row = (name, km, sub, same) =>
        '<div style="display:flex;gap:8px;align-items:baseline;padding:3px 0;'
        + (same ? 'opacity:.5;' : '') + '">'
        + '<span style="font-weight:600;color:#111827;">' + flEsc(name) + '</span>'
        + (sub ? '<span style="color:#9ca3af;font-size:11px;">' + flEsc(sub) + '</span>' : '')
        + '<span style="margin-left:auto;white-space:nowrap;color:#b45309;font-weight:700;">'
        + 'every ' + flNum(km) + ' km' + (same ? ' (same)' : '') + '</span></div>';

    let html = '<div style="border:1px solid #fde68a;background:#fffbeb;border-radius:8px;padding:10px 12px;">'
        + '<div style="font-size:12px;font-weight:800;color:#92400e;margin-bottom:6px;">'
        + '⚠ These hold a schedule of their own</div>'
        + '<div style="font-size:12px;">';
    vs.forEach(v => html += row(v.name, v.interval_km, v.keeper_name || '', v.same_as_default));
    // ⚠ Rider-level schedules are LEGACY and only take effect for someone with no
    //   registered machine — calling them "will ignore the company schedule" named
    //   casualties that mostly aren't. They are still listed (and still cleared by
    //   "put every bike on it"), but labelled for what they are.
    rs.forEach(r => html += row(r.name, r.interval_km, 'older rider schedule', r.same_as_default));
    html += '<div style="font-size:10.5px;color:#92400e;margin-top:6px;line-height:1.4;">'
        + 'Bikes keep their own number instead of the company one. '
        + '<i>Older rider schedules</i> only apply to someone with no registered bike.</div>';
    html += '</div></div>';

    // The choice. Default is LEAVE ALONE — the safe reading, and what this button
    // has always silently done.
    html += '<div style="margin-top:10px;font-size:12.5px;">'
        + '<label style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;cursor:pointer;">'
        + '<input type="radio" name="flDefApply" value="keep" checked style="margin-top:3px;">'
        + '<span><b>Leave them on their own schedule</b>'
        + '<div style="color:#6b7280;font-size:11.5px;">They keep their own numbers. Only the other bikes change.</div>'
        + '</span></label>'
        + '<label style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;cursor:pointer;">'
        + '<input type="radio" name="flDefApply" value="clear" style="margin-top:3px;">'
        + '<span><b>Put every bike on this schedule</b>'
        + '<div style="color:#6b7280;font-size:11.5px;">Clears their own schedules, so they follow the company one '
        + 'from now on — including the next time you change it.</div>'
        + '</span></label></div>';

    box.innerHTML = html;
}

function flCloseDefault() { document.getElementById('flDefModal').style.display = 'none'; }
function flDefError(msg) {
    const el = document.getElementById('flDefError');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

function flSaveDefault() {
    const km = parseInt(String(document.getElementById('flDefKm').value).replace(/[^0-9]/g, ''), 10);
    if (!km || km < 100 || km > 100000) { flDefError('Give a value between 100 and 100,000 km.'); return; }
    const sel = document.querySelector('input[name="flDefApply"]:checked');
    const clear = !!(sel && sel.value === 'clear');

    const btn = document.getElementById('flDefSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    flDefError('');

    fetch(FL_BASE + '/default-interval', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify({ interval_km: km, clear_overrides: clear })
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flDefaultInterval = res.interval_km;
        flCloseDefault();
        alert(res.message);
        flLoad(flMonth);
        if (flSelected) flSelectRider(flSelected);
        if (typeof flvLoad === 'function' && document.getElementById('flvWrap')) flvLoad();
    })
    .catch(err => flDefError(err.message || 'Could not save the default.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Save schedule'; });
}

/** A service HAPPENED — resets the due clock. Never touches the schedule. */
function flMarkServiced(uid, suggested) {
    // ⭐ Every type WITH A SCHEDULE is offered — exactly the ones the service
    // schedule counts down (oil 1,200 · oil+tuning 2,500 · brake shoe 10,000).
    // An earlier cut offered only clock-resetting types, which left Brake Shoe
    // with a countdown on screen and no way to reset it. Recording a non-oil job
    // resets ITS OWN countdown only — the server keeps the bike's overall
    // service-due clock for oil services, so brake shoes can never make an
    // overdue oil change look done.
    // "As conditions" types (Chain Set, Misc) are absent on purpose: nothing to
    // count down to, so file those as a maintenance request with the bill.
    const schedTypes = ((flData && flData.maint_types) || []).filter(t => t.interval_km > 0);
    let typeId = null;

    if (schedTypes.length > 1) {
        const lines = schedTypes.map((t, i) =>
            (i + 1) + '. ' + t.name + ' (every ' + flNum(t.interval_km) + ' km)' +
            (t.resets_service_clock ? '' : ' — own schedule only')).join('\n');
        const pick = window.prompt('Which service was done?\n\n' + lines, '1');
        if (pick === null) return;
        const idx = parseInt(pick, 10) - 1;
        if (isNaN(idx) || idx < 0 || idx >= schedTypes.length) { alert('That is not one of the listed services.'); return; }
        typeId = schedTypes[idx].id;
    } else if (schedTypes.length === 1) {
        typeId = schedTypes[0].id;
    }

    const chosen = schedTypes.find(t => t.id === typeId);
    const v = window.prompt(
        'Odometer reading at this service (km):' +
        (chosen ? '\n\n' + chosen.name + ' — next due ' + chosen.due_label + '.' : '') +
        '\n\nThis records that the service was done and resets the due date.',
        suggested || '');
    if (v === null) return;
    const meter = parseInt(String(v).replace(/[^0-9]/g, ''), 10);
    if (!meter || meter < 0) { alert('Enter the odometer reading in kilometres.'); return; }

    const payload = { rider_id: uid, meter: meter };
    if (typeId) payload.maintenance_type_id = typeId;
    flPostService(payload);
}

/** The SCHEDULE — how often this bike falls due. Never records a service. */
function flSetBikeInterval(uid, currentInterval) {
    const v = window.prompt(
        'Service this bike every how many km?\n\n' +
        'Only this bike. 0 = follow the company default (' + flNum(flDefaultInterval) + ' km).\n' +
        'This does NOT record a service — it only changes how often one is due.',
        currentInterval || '');
    if (v === null) return;
    const km = parseInt(String(v).replace(/[^0-9]/g, ''), 10);
    flPostService({ rider_id: uid, interval_km: isNaN(km) ? 0 : km });
}

function flPostService(payload) {
    fetch(FL_BASE + '/mark-serviced', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { alert(res.message || 'Could not save.'); return; }
        // Echo back WHAT changed — "Service recorded at 33,000 km" vs "Now due
        // every 750 km" is the difference the two buttons exist for.
        if (res.message) alert(res.message);
        flLoad(flMonth);
        flSelectRider(payload.rider_id);
    })
    .catch(() => alert('Could not save. Please try again.'));
}

// =============================================
// NEW BIKE EXPENSE — inline modal
// Posts to the SAME endpoint the full request form uses, so FuelClaimRules
// applies identically. The rider list is the Bikes roster already on screen,
// which is both shorter and more relevant than the company-wide employee list.
// =============================================
let flNewCat = 'Petrol';

function flOpenNew(cat) {
    if (!flExpenseCategoryId) {
        alert('The "Expense" request category is not configured, so a claim cannot be filed from here.');
        return;
    }
    // Leaving edit mode: the modal is shared, so every trace of the last edit has
    // to go or a new claim inherits its disabled rider and hidden pay pickers.
    flEditId = null;
    document.getElementById('flNewEditNote').style.display = 'none';
    document.getElementById('flNewRider').disabled = false;
    document.getElementById('flNewSubmit').textContent = 'Create request';

    flNewCat = cat;
    const isPetrol = cat === 'Petrol';
    document.getElementById('flNewTitle').textContent = isPetrol ? '⛽ New petrol request' : '🔧 New maintenance request';
    document.getElementById('flNewSvcWrap').style.display = isPetrol ? 'none' : 'block';
    flFillMaintTypes();
    document.getElementById('flNewServiceType').value = '';

    // Riders come from the month payload already loaded for this screen.
    const sel = document.getElementById('flNewRider');
    const riders = (flData && flData.riders) ? flData.riders : [];
    sel.innerHTML = '<option value="">— choose a rider —</option>' + riders.map(r =>
        '<option value="' + r.user_id + '" data-company="' + (r.bike === 'company' ? '1' : '0') + '">' +
        flEsc(r.name) + (r.bike === 'company' ? ' — company bike' : ' — own bike') + '</option>').join('');

    document.getElementById('flNewAmount').value = '';
    document.getElementById('flNewMeter').value = '';
    document.getElementById('flNewNote').value = '';
    // LOCAL date, not toISOString() — that is UTC, which on PKT reads as
    // YESTERDAY between midnight and 5am, and as `max` it would even block
    // choosing today. (Same trap as the date-cast Carbon issue elsewhere.)
    const d = new Date();
    const todayYmd = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
                   + '-' + String(d.getDate()).padStart(2, '0');
    document.getElementById('flNewDate').value = todayYmd;
    document.getElementById('flNewDate').max = todayYmd;
    flNewError('');
    flNewFillPaySources();
    flNewRiderChanged();
    document.getElementById('flNewModal').style.display = 'flex';
}

/**
 * Build the "Paid from" list. A user without `expense_all_payment_sources`
 * legitimately gets a single option (the Expense Fund) — the row is still
 * SHOWN, greyed, because "it came out of the fund" is information the person
 * filing wants confirmed, not hidden. The whole block only disappears when the
 * server sent no accounts at all (misconfiguration), and then we submit
 * nothing and the old default applies.
 */
let flNewBankId = null;

function flNewFillPaySources() {
    const wrap = document.getElementById('flNewPayWrap');
    const sel  = document.getElementById('flNewPaySource');
    const list = (flData && flData.pay_sources) ? flData.pay_sources : [];

    flNewBankId = null;
    if (!list.length) { wrap.style.display = 'none'; sel.innerHTML = ''; flNewPayChanged(); return; }

    sel.innerHTML = list.map(a =>
        '<option value="' + a.id + '"' + (a.is_default ? ' selected' : '') +
        ' data-online="' + (a.is_online ? '1' : '0') + '">' +
        flEsc(a.display_name || a.name) + '</option>').join('');
    sel.disabled = (list.length === 1);
    sel.style.background = (list.length === 1) ? '#f9fafb' : '#fff';
    wrap.style.display = 'block';
    flNewPayChanged();
}

/** Bank sources need the specific bank; cash sources must never carry one. */
function flNewPayChanged() {
    const sel  = document.getElementById('flNewPaySource');
    const opt  = sel.options[sel.selectedIndex];
    const isOnline = !!(opt && opt.dataset && opt.dataset.online === '1');
    const bankWrap = document.getElementById('flNewBankWrap');

    document.getElementById('flNewPayHint').textContent = !opt ? ''
        : (sel.disabled ? 'This is the only account you can spend from.'
                        : 'The account this money actually left.');

    if (!isOnline) {
        bankWrap.style.display = 'none';
        flNewBankId = null;
        return;
    }

    const banks = (flData && flData.pay_banks) ? flData.pay_banks : [];
    if (!banks.length) { bankWrap.style.display = 'none'; flNewBankId = null; return; }
    if (!banks.some(b => b.id === flNewBankId)) flNewBankId = null;

    document.getElementById('flNewBankChips').innerHTML = banks.map(b =>
        '<button type="button" onclick="flNewPickBank(' + b.id + ')" ' +
        'style="border:1px solid ' + (b.id === flNewBankId ? '#f59e0b' : '#d1d5db') + ';' +
        'background:' + (b.id === flNewBankId ? '#fffbeb' : '#fff') + ';border-radius:999px;' +
        'padding:5px 11px;font-size:12px;font-weight:700;cursor:pointer;' +
        'color:' + (b.id === flNewBankId ? '#92400e' : '#374151') + ';">' +
        flEsc(b.short_code || b.name) + '</button>').join('');
    bankWrap.style.display = 'block';
}

function flNewPickBank(id) {
    flNewBankId = id;
    flNewPayChanged();
}

function flCloseNew() {
    document.getElementById('flNewModal').style.display = 'none';
    // Drop the bike's record with the modal — reopening for a DIFFERENT rider must
    // never flash the previous machine's history while the new answer is in flight.
    flRenderLastMaint(null);
}

/** May this user manage types / correct a filed claim? Same server-side gate. */
function flCanManageTypes() { return !!(flData && flData.can_manage_types); }

// =============================================
// ✏️ EDIT A FILED CLAIM (pending only)
// Reuses the new-request modal — identical fields, so a second form would have
// meant a second copy of the meter/type/date rules that then drifts.
// =============================================
let flEditId = null;

function flOpenEdit(claimId) {
    // The claim as the server last sent it — searched across the loaded rider's days.
    let claim = null;
    ((flRider && flRider.days) || []).forEach(d => (d.claims || []).forEach(c => { if (c.id === claimId) claim = c; }));
    if (!claim) { alert('Could not find that claim — refresh and try again.'); return; }

    flEditId = claimId;
    flNewCat = claim.kind === 'fuel' ? 'Petrol' : 'Maintenance';
    const isPetrol = flNewCat === 'Petrol';

    document.getElementById('flNewTitle').textContent = isPetrol ? '✏️ Edit petrol claim' : '✏️ Edit maintenance claim';
    document.getElementById('flNewSvcWrap').style.display = isPetrol ? 'none' : 'block';
    flFillMaintTypes();

    // The rider cannot be changed here — moving a claim between riders would move
    // it between two different bikes and odometer histories. Re-file instead.
    // ⚠ The option MUST carry the user id as its value: flNewWhichBike() reads
    //   `.value` to name the machine and fetch its record. A value-less option made
    //   `.value` the rider's NAME, the server cast it to user 0, and the edit modal
    //   silently lost both the which-bike line and the last-maintenance panel.
    const rsel = document.getElementById('flNewRider');
    rsel.innerHTML = '<option value="' + ((flRider && flRider.user_id) || '') + '">'
        + flEsc((flRider && flRider.name) || 'this rider') + '</option>';
    rsel.disabled = true;
    document.getElementById('flNewBikeHint').textContent = 'Filed for this rider — to move it to someone else, reject it and file again.';

    const svcSel = document.getElementById('flNewServiceType');
    svcSel.value = claim.maintenance_type_id ? ('type:' + claim.maintenance_type_id)
                 : (claim.service_type || '');

    document.getElementById('flNewAmount').value = claim.amount ?? '';
    document.getElementById('flNewMeter').value  = claim.meter_at_fill ?? '';
    document.getElementById('flNewNote').value   = claim.note || '';
    const d = (claim.filed_at || '').slice(0, 10);
    if (d) document.getElementById('flNewDate').value = d;

    // Paying is decided at approval, not here — hide the money pickers so an edit
    // cannot quietly re-book the source.
    document.getElementById('flNewPayWrap').style.display = 'none';
    document.getElementById('flNewBankWrap').style.display = 'none';

    const note = document.getElementById('flNewEditNote');
    note.textContent = 'Correcting claim #' + claimId + ' before approval. The rider keeps the claim; only these details change.';
    note.style.display = 'block';

    document.getElementById('flNewSubmit').textContent = 'Save changes';
    flNewError('');
    flNewWhichBike();     // an edit still says which machine it belongs to
    flNewSvcChanged();
    flNewMeterTyped();
    document.getElementById('flNewModal').style.display = 'flex';
}

/** Send just the corrected fields; the server re-runs every filing rule on them. */
function flSubmitEdit() {
    const svc    = document.getElementById('flNewServiceType').value;
    const amount = parseFloat(document.getElementById('flNewAmount').value);
    const meter  = document.getElementById('flNewMeter').value;
    const date   = document.getElementById('flNewDate').value;
    const note   = document.getElementById('flNewNote').value;

    if (!amount || amount <= 0) { flNewError('Enter the amount.'); return; }

    const body = {amount: amount, expense_date: date, description: note};
    if (meter !== '') body.meter_at_fill = parseInt(meter, 10);
    if (flNewCat === 'Maintenance' && svc.indexOf('type:') === 0) {
        body.maintenance_type_id = parseInt(svc.slice(5), 10);
    }

    const btn = document.getElementById('flNewSubmit');
    btn.disabled = true; btn.textContent = 'Saving…';
    flNewError('');

    fetch('/orders/riders-map/fleet/claim/' + flEditId + '/edit', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify(body)
    })
    .then(r => r.json().then(j => ({ok: r.ok, j})))
    .then(({ok, j}) => {
        // The server's own message explains WHY (meter needed, reading out of range).
        if (!ok || !j.success) { flNewError(j.message || 'Could not save the change.'); return; }
        flCloseNew();
        flLoad(flMonth, true);
        if (flSelected) flSelectRider(flSelected);
    })
    .catch(() => flNewError('Could not save the change. Please try again.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Save changes'; });
}

function flNewError(msg) {
    const el = document.getElementById('flNewError');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

/** Tell the manager, before he submits, whether the meter is required. */
function flNewRiderChanged() {
    const sel = document.getElementById('flNewRider');
    const opt = sel.options[sel.selectedIndex];
    const isCompany = opt && opt.dataset && opt.dataset.company === '1';
    document.getElementById('flNewBikeHint').textContent = !opt || !sel.value
        ? '' : (isCompany
            ? 'Company bike — the firm buys the fuel, so the meter is required.'
            : 'Own bike — paid per shift kilometre. The meter is optional here.');
    flNewSvcChanged();
    flNewMeterTyped();          // the since-last-fill line is per rider
    flNewWhichBike();           // ⭐ and WHICH machine this will land on
}

/**
 * ⭐ NAME THE MACHINE BEFORE THE CLAIM IS FILED (owner ask, Aug-5).
 *
 * "So the team knows which bike they are raising for." Asked of the registry, so
 * it follows a reassignment on its own, and keyed to the CLAIM'S DATE rather than
 * today — a backdated claim lands on the bike he had THAT day, which is exactly how
 * the server stamps it. Changing either the rider or the date re-asks.
 *
 * Silent when there is nothing to say (no rider picked, no machine registered):
 * a label is never worth breaking a form over.
 */
let flNewBikeSeq = 0;
function flNewWhichBike() {
    const el  = document.getElementById('flNewWhichBike');
    if (!el) return;
    const uid  = document.getElementById('flNewRider').value;
    const date = document.getElementById('flNewDate').value;
    if (!uid) { el.style.display = 'none'; el.textContent = ''; flRenderLastMaint(null); return; }

    const seq = ++flNewBikeSeq;        // last answer wins, never a stale one
    fetch(FLV_BASE + '/for-user?user_id=' + encodeURIComponent(uid)
          + (date ? '&date=' + encodeURIComponent(date) : ''),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (seq !== flNewBikeSeq) return;
            const v = res && res.vehicle;
            if (!v) { el.style.display = 'none'; el.textContent = ''; flRenderLastMaint(null); return; }
            el.style.display = '';
            el.innerHTML = (v.vtype === 'van' ? '🚚' : '🏍️')
                + ' This will be recorded against <b>' + flEsc(v.name) + '</b>'
                + (v.is_company ? '' : ' <span style="color:#9ca3af;">(his own bike)</span>');
            // ⭐ …and what that machine already has on record.
            flRenderLastMaint(res.last_maintenance || null);
        })
        .catch(() => { if (seq === flNewBikeSeq) { el.style.display = 'none'; flRenderLastMaint(null); } });
}

/**
 * ⭐ "WHAT IS ALREADY THERE" — the machine's maintenance record, shown while the
 *    claim is still being typed (owner ask, Aug-16).
 *
 * Three things, in the order a person actually asks them:
 *   1. what is due next, and how soon — so a service is not filed twice, and a due
 *      one is not missed while the form is open;
 *   2. each scheduled job's last record — WHEN, at what odometer and BY WHOM;
 *   3. the last few entries as filed, pending ones included — because "somebody
 *      already put this in yesterday" is the duplicate this panel exists to stop.
 *
 * Maintenance only. On a petrol claim it would be noise beside the since-last-fill
 * line, so it stays hidden.
 */
function flRenderLastMaint(lm) {
    const box = document.getElementById('flNewLastMaint');
    if (!box) return;
    if (!lm || flNewCat !== 'Maintenance') { box.style.display = 'none'; box.innerHTML = ''; return; }

    const tone = s => s === 'overdue' ? '#b91c1c' : (s === 'due_soon' ? '#b45309' : '#15803d');
    const dueText = t => t.due_in_km === null || t.due_in_km === undefined
        ? 'never recorded'
        : (t.due_in_km < 0 ? flNum(-t.due_in_km) + ' km overdue' : 'due in ' + flNum(t.due_in_km) + ' km');

    let html = '';

    const o = lm.overall;
    if (o && o.due_in_km !== null && o.due_in_km !== undefined) {
        html += '<div style="font-weight:700;color:' + tone(o.state) + ';margin-bottom:5px;">'
            + '🛢 ' + (o.due_type_name ? flEsc(o.due_type_name) : 'Service') + ' — '
            + (o.due_in_km < 0 ? flNum(-o.due_in_km) + ' km overdue' : 'due in ' + flNum(o.due_in_km) + ' km')
            + '</div>';
    }

    const rows = (lm.per_type || []).filter(t => t.last_meter !== null && t.last_meter !== undefined);
    if (rows.length) {
        html += '<div style="color:#6b7280;font-weight:700;font-size:10.5px;letter-spacing:.03em;'
            + 'text-transform:uppercase;margin-bottom:3px;">Last done on this bike</div>';
        rows.forEach(t => {
            html += '<div style="display:flex;gap:6px;align-items:baseline;">'
                + '<span style="color:#111827;font-weight:600;">' + flEsc(t.name) + '</span>'
                + '<span style="color:#6b7280;">' + flNum(t.last_meter) + ' km'
                + (t.last_at ? ' · ' + flDate(t.last_at) : '')
                + (t.last_by ? ' · ' + flEsc(t.last_by) : '')
                + (t.covered_by ? ' · <i>via ' + flEsc(t.covered_by) + '</i>' : '')
                + '</span>'
                + '<span style="margin-left:auto;white-space:nowrap;color:' + tone(t.state) + ';font-weight:600;">'
                + dueText(t) + '</span></div>';
        });
    }

    const recent = (lm.recent || []).slice(0, 3);
    if (recent.length) {
        html += '<div style="color:#6b7280;font-weight:700;font-size:10.5px;letter-spacing:.03em;'
            + 'text-transform:uppercase;margin:7px 0 3px;">Recent entries</div>';
        recent.forEach(r => {
            html += '<div style="color:#6b7280;">'
                + flDate(r.date) + ' · ' + flEsc(r.kind)
                + ' · Rs ' + flNum(r.amount)
                + (r.meter ? ' · ' + flNum(r.meter) + ' km' : '')
                + ' · ' + flEsc(r.by_name || '')
                + (r.is_pending ? ' <b style="color:#b45309;">(pending)</b>' : '')
                + '</div>';
        });
    }

    if (!html) {
        html = '<span style="color:#9ca3af;">No maintenance recorded on this bike yet.</span>';
    }
    box.innerHTML = html;
    box.style.display = '';
}

/**
 * "That's N km since his last fill" while the manager types the odometer — the
 * same figure that will sit on the claim for whoever approves it. Petrol only,
 * and silent unless we actually have a previous fill reading to measure from.
 */
function flNewMeterTyped() {
    const el = document.getElementById('flNewSince');
    const sel = document.getElementById('flNewRider');
    const r = ((flData && flData.riders) || []).find(x => String(x.user_id) === String(sel.value));
    const prev = r ? r.last_fill_meter : null;
    const now = parseInt(document.getElementById('flNewMeter').value, 10);

    if (flNewCat !== 'Petrol' || !prev || !now || isNaN(now)) { el.style.display = 'none'; return; }
    const gap = now - prev;
    if (gap <= 0) {
        el.style.color = '#b45309';
        el.textContent = '⚠ That is not above his last fill reading (' + flNum(prev) + ' km).';
    } else if (gap > 2000) {
        el.style.color = '#b45309';
        el.textContent = '⚠ ' + flNum(gap) + ' km since his last fill (' + flNum(prev) + ') — check the reading.';
    } else {
        el.style.color = '#3730a3';
        el.textContent = '▲ ' + flNum(gap) + ' km since his last fill (' + flNum(prev) + ' km).';
    }
    el.style.display = 'block';
}

// =============================================
// ⚙️ MAINTENANCE TYPES — the manager's own category list
// Server is the authority: every write returns the full list and we re-render
// from that, so the screen can never drift from what was actually stored.
// =============================================
const FL_TYPES_URL = '/orders/riders-map/fleet/maintenance-types';

function flOpenTypes() {
    document.getElementById('flTypesModal').style.display = 'flex';
    flResetTypeForm();
    flLoadTypes();
}
function flCloseTypes() { document.getElementById('flTypesModal').style.display = 'none'; }

function flLoadTypes() {
    fetch(FL_TYPES_URL, {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(flRenderTypes)
        .catch(() => { document.getElementById('flTypesBody').textContent = 'Could not load the types.'; });
}

function flRenderTypes(d) {
    const body = document.getElementById('flTypesBody');
    if (!d || !d.success) { body.textContent = 'Could not load the types.'; return; }
    if (!d.available) {
        body.innerHTML = '<i>Maintenance types are not set up on this database yet (SQL batch 12).</i>';
        document.getElementById('flTypesForm').style.display = 'none';
        return;
    }
    document.getElementById('flTypesForm').style.display = d.can_manage ? 'block' : 'none';

    const rows = d.types || [];
    if (!rows.length) { body.innerHTML = '<i>No types yet — add the first one below.</i>'; return; }

    const group = (bucket, label) => {
        const list = rows.filter(t => t.bucket === bucket);
        if (!list.length) return '';
        return '<div style="margin-bottom:10px;">'
            + '<div style="font-size:11.5px;font-weight:700;color:#6b7280;margin-bottom:4px;">' + label + '</div>'
            + list.map(t =>
                '<div style="display:flex;align-items:center;gap:8px;padding:6px 2px;border-bottom:1px solid #f3f4f6;'
                + (t.is_active ? '' : 'opacity:.55;') + '">'
                + '<div style="flex:1;min-width:0;">'
                +   '<b style="font-size:13px;">' + flEsc(t.name) + '</b>'
                +   (t.is_active ? '' : ' <span style="font-size:11px;color:#b45309;">(retired)</span>')
                +   '<div style="font-size:11.5px;color:#6b7280;">Due ' + flEsc(t.due_label)
                +   (t.resets_service_clock ? ' · resets the service clock' : '') + '</div>'
                + '</div>'
                + (d.can_manage
                    ? '<button type="button" onclick="flEditType(' + t.id + ')" style="padding:3px 9px;font-size:12px;'
                      + 'border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;">Edit</button>'
                      + '<button type="button" onclick="flDeleteType(' + t.id + ',\'' + flEsc(t.name).replace(/'/g, "\\'") + '\')" '
                      + 'style="padding:3px 9px;font-size:12px;border:1px solid #fecaca;background:#fff;color:#b91c1c;'
                      + 'border-radius:6px;cursor:pointer;">' + (t.is_active ? 'Retire' : 'Delete') + '</button>'
                    : '')
                + '</div>').join('')
            + '</div>';
    };
    body.innerHTML = group('regular', '🛢️ Regular service') + group('repair', '🔧 Repair');

    // Keep the picker on the create form in step with what was just edited.
    if (flData) { flData.maint_types = rows.filter(t => t.is_active); }
    window._flTypes = rows;
}

function flResetTypeForm() {
    document.getElementById('flTypeId').value = '';
    document.getElementById('flTypeName').value = '';
    document.getElementById('flTypeBucket').value = 'regular';
    document.getElementById('flTypeInterval').value = '';
    document.getElementById('flTypeResets').checked = false;
    document.getElementById('flTypeSaveBtn').textContent = 'Save';
    flTypesError('');
    flTypeBucketChanged();
}

function flTypesError(msg) {
    const el = document.getElementById('flTypesError');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

/** A repair never resets the service clock — the server enforces it too. */
function flTypeBucketChanged() {
    const isRegular = document.getElementById('flTypeBucket').value === 'regular';
    document.getElementById('flTypeResetWrap').style.display = isRegular ? 'block' : 'none';
    if (!isRegular) document.getElementById('flTypeResets').checked = false;
}

function flEditType(id) {
    const t = (window._flTypes || []).find(x => x.id === id);
    if (!t) return;
    document.getElementById('flTypeId').value = t.id;
    document.getElementById('flTypeName').value = t.name;
    document.getElementById('flTypeBucket').value = t.bucket;
    document.getElementById('flTypeInterval').value = t.interval_km || '';
    document.getElementById('flTypeResets').checked = !!t.resets_service_clock;
    document.getElementById('flTypeSaveBtn').textContent = 'Save changes';
    flTypeBucketChanged();
    flTypesError('');
}

function flSaveType() {
    const id = document.getElementById('flTypeId').value;
    const name = document.getElementById('flTypeName').value.trim();
    if (!name) { flTypesError('Give the type a name.'); return; }

    const body = {
        type_name: name,
        bucket: document.getElementById('flTypeBucket').value,
        interval_km: parseInt(document.getElementById('flTypeInterval').value, 10) || 0,
        resets_service_clock: document.getElementById('flTypeResets').checked,
        is_active: true,
    };
    fetch(FL_TYPES_URL + (id ? '/' + id : ''), {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify(body)
    }).then(r => r.json()).then(d => {
        if (!d.success) { flTypesError(d.message || 'Could not save.'); return; }
        flRenderTypes(d);
        flResetTypeForm();
    }).catch(() => flTypesError('Could not save. Please try again.'));
}

function flDeleteType(id, name) {
    if (!confirm('Remove "' + name + '"? If it has already been used it is kept on those claims and just hidden from the list.')) return;
    fetch(FL_TYPES_URL + '/' + id, {
        method: 'DELETE',
        headers: {'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''}
    }).then(r => r.json()).then(d => {
        if (!d.success) { flTypesError(d.message || 'Could not remove.'); return; }
        flRenderTypes(d);
        flResetTypeForm();
    }).catch(() => flTypesError('Could not remove. Please try again.'));
}

/**
 * Fill the "what was done" list from the manager's own types, grouped
 * Regular / Repair. Leaves the built-in two-option list alone when types are not
 * configured yet, so the form still works before SQL batch 12 runs.
 */
function flFillMaintTypes() {
    const sel = document.getElementById('flNewServiceType');
    const types = (flData && flData.maint_types) ? flData.maint_types : [];
    if (!sel || !types.length) return;

    const group = (bucket, label) => {
        const rows = types.filter(t => t.bucket === bucket);
        if (!rows.length) return '';
        return '<optgroup label="' + label + '">' + rows.map(t =>
            '<option value="type:' + t.id + '" data-bucket="' + t.bucket + '"' +
            ' data-due="' + flEsc(t.due_label) + '">' + flEsc(t.name) + '</option>').join('') + '</optgroup>';
    };
    sel.innerHTML = '<option value="">Not a bike / other maintenance</option>'
        + group('regular', '🛢️ Regular service')
        + group('repair', '🔧 Repair');
}

/** The chosen option's bucket — drives the meter requirement. */
function flSvcBucket() {
    const sel = document.getElementById('flNewServiceType');
    const opt = sel ? sel.options[sel.selectedIndex] : null;
    if (!opt || !opt.value) return '';
    // Typed option carries its bucket; the legacy fallback list carries the raw
    // machine value.
    if (opt.dataset && opt.dataset.bucket) return opt.dataset.bucket;
    return opt.value === 'oil_change' ? 'regular' : (opt.value === 'repair' ? 'repair' : '');
}

function flNewSvcChanged() {
    const sel = document.getElementById('flNewRider');
    const opt = sel.options[sel.selectedIndex];
    const isCompany = opt && opt.dataset && opt.dataset.company === '1';
    const bucket = flSvcBucket();
    // Required on a company bike for petrol, and for a SCHEDULED service (a
    // service with no odometer can never reset the bike's service clock).
    // A repair never needs it.
    const need = isCompany && (flNewCat === 'Petrol' || bucket === 'regular');
    document.getElementById('flNewMeterReq').textContent = need ? '(required)' : '(optional)';
    document.getElementById('flNewMeterHint').textContent = flNewCat === 'Petrol'
        ? 'The odometer at the moment of filling — this is what links the fill to the km ridden.'
        : (bucket === 'regular' ? 'The odometer at the service — this resets the bike\'s service-due clock on approval.' : '');

    // "every 1,200 km" / "as conditions" — the manager's own schedule for this job.
    const due = document.getElementById('flNewSvcDue');
    const svcOpt = document.getElementById('flNewServiceType').selectedOptions[0];
    if (due) due.textContent = (svcOpt && svcOpt.dataset && svcOpt.dataset.due) ? ('Due ' + svcOpt.dataset.due) : '';
}

function flSubmitNew() {
    // The same button serves both jobs — the modal is shared, so the verb is too.
    if (flEditId) { flSubmitEdit(); return; }

    const uid    = document.getElementById('flNewRider').value;
    const amount = parseFloat(document.getElementById('flNewAmount').value);
    const date   = document.getElementById('flNewDate').value;
    const meter  = document.getElementById('flNewMeter').value;
    const svc    = document.getElementById('flNewServiceType').value;
    const note   = document.getElementById('flNewNote').value;

    const paySel  = document.getElementById('flNewPaySource');
    const payId   = (paySel && paySel.value) ? parseInt(paySel.value, 10) : null;
    const payOpt  = paySel ? paySel.options[paySel.selectedIndex] : null;
    const payIsOnline = !!(payOpt && payOpt.dataset && payOpt.dataset.online === '1');

    if (!uid) { flNewError('Choose which rider this is for.'); return; }
    if (!amount || amount <= 0) { flNewError('Enter the amount.'); return; }
    if (!date) { flNewError('Choose the date.'); return; }
    // Caught here as well as server-side so the manager fixes it in place
    // instead of losing the form to a 422.
    if (payIsOnline && !flNewBankId) { flNewError('Choose which bank this online payment came from.'); return; }

    const btn = document.getElementById('flNewSubmit');
    btn.disabled = true; btn.textContent = 'Creating…';
    flNewError('');

    const body = {
        category_id: flExpenseCategoryId,
        requester_user_id: parseInt(uid, 10),
        title: flNewCat,
        description: note || (flNewCat + ' recorded from the Bikes screen'),
        amount: amount,
        expense_category: flNewCat,
        expense_date: date,
    };
    if (meter !== '') body.meter_at_fill = parseInt(meter, 10);
    // A typed option sends the type id and lets the SERVER derive service_type
    // from its bucket; the legacy fallback list still sends the raw value.
    if (flNewCat === 'Maintenance' && svc) {
        if (svc.indexOf('type:') === 0) body.maintenance_type_id = parseInt(svc.slice(5), 10);
        else body.service_type = svc;
    }
    if (payId) body.payment_source_account_id = payId;
    // Only ever sent with a bank source — the server drops it otherwise, but a
    // cash claim should not carry a bank id in the first place.
    if (payId && payIsOnline && flNewBankId) body.receiving_account_id = flNewBankId;

    fetch('/requests', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(body)
    })
    .then(r => r.json().then(j => ({ok: r.ok, j})))
    .then(({ok, j}) => {
        // The server's own message is shown verbatim — it is the one that
        // explains WHY (meter required, reading out of range, double tap).
        if (!ok || !j.success) { flNewError(j.message || 'Could not create the request.'); return; }
        flCloseNew();
        flLoad(flMonth, true);              // money + counts move immediately
        if (flSelected) flSelectRider(flSelected);
    })
    .catch(() => flNewError('Could not create the request. Please try again.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Create request'; });
}

// ---- photo lightbox ----
function flPhoto(url) {
    document.getElementById('flLightboxImg').src = url;
    document.getElementById('flLightbox').style.display = 'flex';
}
function flClosePhoto() {
    document.getElementById('flLightbox').style.display = 'none';
    document.getElementById('flLightboxImg').src = '';
}

// ---- helpers ----
// Why a day has no distance. Leave/absent are states, not failures — only
// "worked but no usable reading" is worth an alert. Mirrors the mobile screen
// and the attendance screen's own classification of the same date.
const FL_DAY_TEXT = {
    leave: '🌴 on leave',
    absent: '— absent',
    no_attendance: '— no attendance recorded',
    in_progress: '— still on shift',
    no_reading: '⚠ worked, no meter reading',
    no_start: '⚠ worked, start meter missing',
    no_end: '⚠ worked, end meter missing',
    unusable: '⚠ meter reading unusable',
};

/** service_type → words a manager reads. Regular service is what resets the due-clock. */
function flServiceLabel(t) {
    return {oil_change: 'regular service', general: 'general service', repair: 'repair', other: 'other'}[t] || 'maintenance';
}
function flNum(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString('en-PK', { maximumFractionDigits: 0 });
}
function flRs(n) { return 'Rs ' + flNum(n); }
function flDate(d) {
    if (!d) return '—';
    const x = new Date(String(d).substring(0, 10) + 'T12:00:00');
    if (isNaN(x)) return d;
    return x.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
}
function flMonthLabel(m) {
    if (!m) return '';
    const x = new Date(m + '-01T12:00:00');
    return isNaN(x) ? m : x.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}
function flEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/* ═══════════════════════════════════════════════════════════════════════════
   VEHICLES (Aug-2026) — the machines, and who has them.

   Kept in its own block with an `flv` prefix so it cannot collide with the costs
   screen above. The two views share only the tab and the toggle; switching
   between them never reloads the other's data.
   ═══════════════════════════════════════════════════════════════════════════ */

const FLV_BASE = FL_BASE + '/vehicles';
let flvMode      = 'riders';   // which view the tab is showing
let flvData      = null;       // last /vehicles payload
let flvOpenId    = null;       // vehicle whose profile is expanded
let flvLoaded    = false;      // fetched at least once
let flvPreviewSeq = 0;         // guards against a slow preview landing after a newer one

/** Switch between the costs table and the machines. */
function flSetMode(mode) {
    flvMode = mode;
    const isVeh = mode === 'vehicles';

    document.getElementById('flModeRiders').classList.toggle('on', !isVeh);
    document.getElementById('flModeVehicles').classList.toggle('on', isVeh);

    // Everything the COSTS view owns — including the month picker, which means
    // nothing here — is hidden as one group.
    ['flMonthWrap', 'flHeadline', 'flVerdict', 'flNotes', 'flDetail'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (isVeh) { el.dataset.flPrevDisplay = el.style.display || ''; el.style.display = 'none'; }
        else if (el.dataset.flPrevDisplay !== undefined) { el.style.display = el.dataset.flPrevDisplay; }
    });
    const tw = document.querySelector('#fleetView .fl-tablewrap');
    if (tw) tw.style.display = isVeh ? 'none' : '';
    const nr = document.querySelector('#fleetView .fl-newreq');
    if (nr) nr.style.display = isVeh ? 'none' : '';

    document.getElementById('flVehWrap').style.display = isVeh ? '' : 'none';
    if (isVeh && !flvLoaded) flvLoad();
}

function flvLoad() {
    const grid = document.getElementById('flVehGrid');
    grid.innerHTML = '<div class="fl-empty">Loading…</div>';

    fetch(FLV_BASE, { headers: { 'Accept': 'application/json' } })
        .then(r => r.status === 403 ? Promise.reject(new Error('403')) : r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flvLoaded = true;
            flvData = res;

            // The SQL has not been run yet — say so plainly instead of showing an
            // empty grid that looks like "there are no vehicles".
            if (res.available === false) {
                document.getElementById('flVehIntro').innerHTML =
                    '<div class="fl-vwarn">The vehicle list is not set up on this server yet '
                    + '(database batch 13 has not been run). Nothing else on this page is affected.</div>';
                grid.innerHTML = '';
                return;
            }
            flvRenderIntro(res);
            flvRenderGrid(res.vehicles || []);
        })
        .catch(err => {
            grid.innerHTML = '<div class="fl-empty">'
                + (err.message === '403'
                    ? 'You do not have permission to see the fleet.'
                    : 'Could not load the vehicles. Please try again.')
                + '</div>';
        });
}

function flvRenderIntro(res) {
    const el = document.getElementById('flVehIntro');
    let h = 'Every machine the company runs, and who has it now. '
          + 'Meter readings and service history follow the <b>vehicle</b>, so a bike keeps its own '
          + 'record when it changes hands.';

    // Honest about the fact that Phase A records but does not yet enforce.
    if (res.rules_enabled === false) {
        h += ' <span style="color:#92400e;">Assignments are being recorded now; the meter rules '
           + 'still follow the rider until this is switched on.</span>';
    }
    if (res.can_manage) {
        h += ' <button type="button" class="fl-vbtn primary" style="margin-left:8px;" '
           + 'onclick="flvOpenEdit(null)">➕ Add a vehicle</button>';
    }
    // 📍 The PERMANENT door to the meet-up points list.
    //
    // ⚠ Without this the list was only reachable from the ⚙ on the store's van
    //   panel — which only exists WHILE A VAN IS OUT. So the stops a van run
    //   depends on could not be set up before the first van run: chicken and egg.
    //   Vehicles is the right home for it (it is van infrastructure, next to the
    //   van itself), and it is always reachable. `vpOpenStops` lives in the
    //   van-panel partial, which is in the DOM on this page whether or not the
    //   live view is showing — so the same modal serves both doors.
    if (typeof vpOpenStops === 'function') {
        h += ' <button type="button" class="fl-vbtn" style="margin-left:6px;" '
           + 'onclick="vpOpenStops()" title="Where riders collect their orders from the van">'
           + '📍 Meet-up points</button>';
    }
    el.innerHTML = h;
}

/**
 * ⭐⭐ THE FLEET AS WORKING PAIRS, NOT AS A LIST OF OBJECTS (owner ruling R4, Aug-6).
 *
 * The old grid was one card per machine, which meant a rider on a company bike
 * appeared TWICE — once on the bike he rides and once as the ⚠ "Not assigned to
 * anyone" own bike he has parked. And a rider with NO machine appeared nowhere at
 * all, which is exactly the state that most needs a manager's attention.
 *
 * So the screen is now two sides:
 *   LEFT  "On the road"    — machine + rider, one card per working pair
 *   RIGHT "Parked & spare" — idle machines, then RIDERS WITH NO MACHINE
 *
 * A parked own bike says "Danish is on DCR-799" instead of raising an alarm about
 * a bike that is exactly where it should be. And "no bike" becomes a visible,
 * assignable state rather than an absence you have to notice.
 */
function flvRenderGrid(list) {
    const grid = document.getElementById('flVehGrid');
    if (!list.length) {
        grid.innerHTML = '<div class="fl-empty">No vehicles yet.</div>';
        return;
    }

    const onRoad = list.filter(v => v.keeper_user_id && v.is_active);
    const idle   = list.filter(v => !v.keeper_user_id && v.is_active);
    const retired = list.filter(v => !v.is_active);

    // Who is on a machine right now — so a parked own bike can name where its
    // owner actually is instead of reading as a fault.
    const keeperOf = {};
    onRoad.forEach(v => { keeperOf[v.keeper_user_id] = v; });

    // ⭐ RIDERS WITH NO MACHINE. Derived from the roster's has_vehicle, which the
    //   server computes from the OPEN ASSIGNMENT (never the stale profile mirror).
    const freeRiders = ((flvData && flvData.riders) || []).filter(r => !r.has_vehicle);

    const company = a => (a.is_company ? 0 : 1);
    onRoad.sort((a, b) => company(a) - company(b) || a.name.localeCompare(b.name));
    idle.sort((a, b) => company(a) - company(b) || a.name.localeCompare(b.name));

    const left = '<div class="fl-vcol">'
        + '<h4 class="fl-vcolh">🛣️ On the road <span>' + onRoad.length + '</span></h4>'
        // ⚠ NOT `.map(flvCard)` — map passes the INDEX as the second argument, which
        //   would arrive as the keeper lookup. Harmless for these cards today, but
        //   only by accident; pass it explicitly so it stays true.
        + (onRoad.length ? onRoad.map(v => flvCard(v, keeperOf)).join('')
            : '<div class="fl-empty" style="padding:14px;">Nobody is on a machine right now.</div>')
        + '</div>';

    const right = '<div class="fl-vcol">'
        + '<h4 class="fl-vcolh">🅿️ Parked &amp; spare <span>'
            + (idle.length + freeRiders.length) + '</span></h4>'
        + (idle.length ? idle.map(v => flvCard(v, keeperOf)).join('') : '')
        + (freeRiders.length
            ? '<div class="fl-vsubh">Riders with no machine</div>'
              + freeRiders.map(flvFreeRiderCard).join('')
            : '')
        + (!idle.length && !freeRiders.length
            ? '<div class="fl-empty" style="padding:14px;">Every machine is out and every rider has one.</div>'
            : '')
        + (retired.length
            ? '<details class="fl-vretired"><summary>' + retired.length + ' retired</summary>'
              + retired.map(v => flvCard(v, keeperOf)).join('') + '</details>'
            : '')
        + '</div>';

    grid.innerHTML = '<div class="fl-vsplit">' + left + right + '</div>';
}

/**
 * A rider holding nothing. The point of showing him at all: under the registry
 * rules he is no longer asked for meter readings and cannot file his own fuel —
 * correct while he really has no bike, and quietly wrong the moment he is riding
 * something nobody recorded. Putting him on screen with an Assign button is what
 * keeps that from going unnoticed.
 */
function flvFreeRiderCard(r) {
    const canManage = flvData && flvData.can_manage;
    const first = (r.name || '').split(' ')[0];
    return ''
      + '<div class="fl-vcard fl-vrider">'
      +   '<div class="fl-vtop">'
      +     '<div class="fl-vnophoto">👤</div>'
      +     '<div style="min-width:0;flex:1;">'
      +       '<div class="fl-vname">' + flEsc(r.name)
      +         '<span class="fl-vtag none">no bike</span></div>'
      +       '<div class="fl-vsub">'
      +         (r.free_since
                    ? 'Has had no machine since ' + flvDate(r.free_since)
                    : 'No machine on record')
      +       '</div>'
      +     '</div>'
      +   '</div>'
      +   '<div class="fl-vnote">'
      +     (flvData && flvData.rules_enabled
                ? 'Not asked for meter readings, and cannot file his own fuel, while this is the case.'
                : 'Once vehicle rules are switched on he will not be asked for meter readings.')
      +   '</div>'
      +   (canManage
            ? '<div class="fl-vfoot">'
              + '<button type="button" class="fl-vbtn primary" onclick="flvAssignToRider(' + r.user_id + ')">'
              +   '➕ Give him a bike</button>'
              // ⚠⚠ ID ONLY — never a name in an inline onclick. JSON.stringify puts
              //   DOUBLE QUOTES inside the double-quoted attribute, which truncates
              //   it ("flvNewOwnBike(95," + junk attributes) and makes the click a
              //   silent SyntaxError. That is why "New own bike" did nothing for
              //   Rajab on prod AND dev (14 Aug) — broken for every rider since the
              //   button shipped, invisible in every log because no request ever
              //   fired. The name is looked up from flvData inside the function.
              + '<button type="button" class="fl-vbtn" onclick="flvNewOwnBike(' + r.user_id + ')"'
              +   ' title="Register a bike he owns himself and assign it to him">'
              +   '🏍️ New own bike</button>'
              + '</div>'
            : '')
      + '</div>';
}

/**
 * ➕ Give a machine to a rider — the reverse of the usual flow (pick the man, then
 * the machine). Opens the ordinary assign modal on the first spare, with the rider
 * preselected; the vehicle dropdown lets the manager change it.
 */
function flvAssignToRider(userId) {
    // ⚠⚠ THE 12:05 LESSON (7 Aug, prod): this used to preselect the first spare of
    //    ANY kind — which that morning was "Danish - own bike", and one Save put
    //    Waseem on Danish's personal machine. Another man's own bike is NEVER a
    //    quick pick: only company machines and the rider's OWN bike qualify here.
    //    (Genuinely lending X's personal bike to Y stays possible from the bike's
    //    own card, where the manager can see whose it is.)
    const spare = ((flvData && flvData.vehicles) || [])
        .filter(v => !v.keeper_user_id && v.is_active
                     && (v.is_company || v.last_keeper_user_id === userId));
    if (!spare.length) {
        alert('There is no spare company machine (and he has no own bike on record). '
            + 'Add a vehicle, take one back from another rider, or use "New own bike".');
        return;
    }
    // His own bike first — giving a man his own machine back is the common case.
    spare.sort((a, b) => (a.last_keeper_user_id === userId ? 0 : 1) - (b.last_keeper_user_id === userId ? 0 : 1));
    flvOpenAssign(spare[0].id, userId);
}

/**
 * 🏍️ Register a rider's OWN bike and hand it to him in one step — the onboarding
 * path for a new rider who arrives with his own machine (owner asked, Aug-6).
 * Without this, "where do I enter him?" is a two-screen answer nobody remembers.
 */
function flvNewOwnBike(userId) {
    // The name comes from the SAME payload that rendered the card — passing it
    // through an HTML attribute is what broke this button (see the call site).
    const rider = ((flvData && flvData.riders) || []).find(r => r.user_id === userId);
    const name = (rider && rider.name) || 'Rider';
    flvOpenEdit(null);
    document.getElementById('flvEditNick').value = name + ' - own bike';
    document.getElementById('flvEditCompany').checked = false;   // his bike, his fuel
    document.getElementById('flvEditTitle').textContent = 'Add ' + name + '’s own bike';
    flvPendingAssignUser = userId;      // handed to him the moment it is created
}

function flvCard(v, keeperOf) {
    const canManage = flvData && flvData.can_manage;
    const icon      = v.vtype === 'van' ? '🚚' : '🏍️';
    const photo     = v.photo_count > 0 && v.first_photo_url
        ? '<img class="fl-vphoto" src="' + flEsc(v.first_photo_url) + '" alt="">'
        : '<div class="fl-vnophoto">' + icon + '</div>';

    // ⭐ An idle OWN bike whose owner is out on a company machine is not a problem
    //   — it is a bike parked exactly where it should be. Saying "⚠ Not assigned to
    //   anyone" about it sent managers looking for a fault that did not exist.
    //   The owner is found from the vehicle's own name-holder: whoever last held it.
    let parkedBecause = null;
    if (!v.keeper_user_id && !v.is_company && keeperOf && v.last_keeper_user_id) {
        const on = keeperOf[v.last_keeper_user_id];
        if (on) parkedBecause = (v.last_keeper_name || 'its owner').split(' ')[0] + ' is on ' + on.name;
    }

    const keeper = v.keeper_name
        ? '<div class="fl-vkeeper">👤 <b>' + flEsc(v.keeper_name) + '</b>'
            + (v.assigned_on ? ' <span style="color:#9ca3af;">since ' + flvDate(v.assigned_on) + '</span>' : '')
          + '</div>'
        : (parkedBecause
            ? '<div class="fl-vkeeper parked">🅿️ Parked — ' + flEsc(parkedBecause) + '</div>'
            : '<div class="fl-vkeeper none">Nobody has this one — '
              + (canManage ? 'give it to a rider below.' : 'not assigned.') + '</div>');

    const s = v.service || {};
    let svc = '<span class="fl-vchip unk">service unknown</span>';
    if (s.due_in_km !== null && s.due_in_km !== undefined) {
        if (s.due_in_km < 0)        svc = '<span class="fl-vchip over">🛢 ' + flNum(-s.due_in_km) + ' km overdue</span>';
        else if (s.state === 'due_soon') svc = '<span class="fl-vchip due">🛢 due in ' + flNum(s.due_in_km) + ' km</span>';
        else                        svc = '<span class="fl-vchip ok">🛢 due in ' + flNum(s.due_in_km) + ' km</span>';
    }

    return ''
      + '<div class="fl-vcard' + (v.is_active ? '' : ' retired') + '" onclick="flvOpen(' + v.id + ')">'
      +   '<div class="fl-vtop">'
      +     photo
      +     '<div style="min-width:0;flex:1;">'
      +       '<div class="fl-vname">' + flEsc(v.name)
      +         '<span class="fl-vtag ' + (v.is_company ? 'co' : 'own') + '">'
      +           (v.is_company ? 'company' : 'own') + '</span>'
      +       '</div>'
      +       '<div class="fl-vsub">' + icon + ' ' + flEsc(v.make_model || (v.vtype === 'van' ? 'Van' : 'Bike'))
      +         (v.base && v.base.has_base ? ' · 📍 parked at a fixed spot' : '')
      +         (v.is_active ? '' : ' · retired')
      +       '</div>'
      +     '</div>'
      +   '</div>'
      +   keeper
      /* ⭐ STANDING ALERT (owner ruling Aug-4): a company machine whose overnight
         and morning meter checks have nowhere to measure from. It stays on the
         card until the pin is saved, then disappears by itself — nothing to
         dismiss. Quiet for personal or unassigned machines. */
      +   (v.needs_home_pin
            ? '<div class="fl-vwarnpin">⚠ No home location for '
              + flEsc((v.keeper_name || 'the rider').split(' ')[0])
              + ' — overnight and morning meter checks cannot run. '
              + '<b>Set it on the Riders page.</b></div>'
            : '')
      +   '<div class="fl-vstats">'
      +     '<span>' + (v.current_meter !== null ? flNum(v.current_meter) + ' km' : 'no reading yet') + '</span>'
      +     svc
      +     (v.photo_count ? '<span>📷 ' + v.photo_count + '</span>' : '')
      +   '</div>'
      +   '<div class="fl-vfoot" onclick="event.stopPropagation()">'
      +     (canManage
        ? '<button type="button" class="fl-vbtn primary" onclick="flvOpenAssign(' + v.id + ')">'
            + (v.keeper_user_id ? '🔄 Reassign' : '➕ Assign') + '</button>'
          + (v.keeper_user_id ? '<button type="button" class="fl-vbtn" onclick="flvRelease(' + v.id + ')">Take back</button>' : '')
          + '<button type="button" class="fl-vbtn" onclick="flvOpenEdit(' + v.id + ')">✏️ Edit</button>'
        : '')
      +     '<button type="button" class="fl-vbtn" style="margin-left:auto;" onclick="flvOpen(' + v.id + ')">Profile ▸</button>'
      +   '</div>'
      + '</div>';
}

/**
 * ⭐ Which half of "this month" the profile is showing (owner ruling Aug-5):
 *    'money' = what was spent (the default, unchanged), 'days' = where the
 *    kilometres went. The day list is fetched only when asked for — see the
 *    note on VehicleController::days.
 *
 * ⚠ Named `flvDetailMode`, NOT `flvMode` — that one already means riders-vs-
 *   vehicles for the whole tab (see flSetMode above). Re-declaring it here was a
 *   SyntaxError that killed this entire script block, so the Bikes tab never
 *   loaded at all.
 */
let flvDetailMode = 'money';
let flvLastRes = null;
const flvDaysCache = {};          // 'vehicleId|month' → the day payload

function flvDaysKey(res) { return res.vehicle.id + '|' + (res.month || flMonth); }

function flvSetDetailMode(mode) {
    flvDetailMode = mode;
    if (!flvLastRes) return;
    const key = flvDaysKey(flvLastRes);

    if (mode === 'days' && !flvDaysCache[key]) {
        flvDaysCache[key] = { loading: true };
        flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
        fetch(FLV_BASE + '/' + flvLastRes.vehicle.id + '/days?month='
              + encodeURIComponent(flvLastRes.month || flMonth),
              { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => { flvDaysCache[key] = res && res.success ? res : { error: true }; })
            .catch(() => { flvDaysCache[key] = { error: true }; })
            .finally(() => {
                // Only redraw if the manager is still looking at this bike and mode.
                if (flvLastRes && flvDaysKey(flvLastRes) === key && flvDetailMode === 'days') {
                    flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
                }
            });
        return;
    }
    flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
}

/** Expand one vehicle: condition photos, who has had it, and its service state. */
function flvOpen(id) {
    flvOpenId = id;
    flvDetailMode = 'money';      // every open starts on the cheap view
    const box = document.getElementById('flVehDetail');
    box.style.display = '';
    box.innerHTML = '<div class="fl-vdbody">Loading…</div>';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // ⚠ Pass the month the manager is actually looking at. Without it the popover
    //   always reported the CURRENT month while the table behind it showed another.
    fetch(FLV_BASE + '/' + id + '?month=' + encodeURIComponent(flMonth),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flvLastRes = res;
            flvRenderDetail(res.vehicle, res.can_manage, res);
        })
        .catch(() => { box.innerHTML = '<div class="fl-vdbody">Could not load that vehicle.</div>'; });
}

/**
 * The month's kilometres, day by day — the receipt behind the figure above it.
 * Every stretch the machine moved is on one of these lines, so the four totals
 * add back up to the headline number (the server says whether they did).
 */
function flvDaysHtml(res) {
    const d = flvDaysCache[flvDaysKey(res)];
    if (!d)          return '';
    if (d.loading)   return '<div style="font-size:12px;color:#9ca3af;">Loading the day list…</div>';
    if (d.error)     return '<div style="font-size:12px;color:#b91c1c;">Could not load the day list.</div>';

    const t = d.totals || {};
    const cell = (n, label, tone) =>
        '<div style="flex:1;min-width:74px;text-align:center;padding:7px 4px;background:#f9fafb;'
      + 'border:1px solid #e5e7eb;border-radius:8px;">'
      + '<div style="font-size:15px;font-weight:700;color:' + (tone || '#111827') + ';">' + flNum(n) + '</div>'
      + '<div style="font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">' + label + '</div>'
      + '</div>';

    const strip = '<div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;">'
        + cell(t.on_duty || 0, 'on duty')
        + cell(t.off_duty || 0, 'off duty', (t.off_duty ? '#b45309' : null))
        + (t.shared ? cell(t.shared, '🔁 shared', '#0f766e') : '')
        + (t.transfer ? cell(t.transfer, '🔁 in transit', '#0f766e') : '')
        + (t.unaccounted ? cell(t.unaccounted, 'unaccounted', '#b91c1c') : '')
        + cell(t.total || 0, 'total')
        + '</div>';

    // ⭐ Say plainly whether the rows behind the total actually make it up. A list
    //    that quietly disagrees with the number above it is worse than no list.
    const note = d.reconciles
        ? '<div style="font-size:11.5px;color:#6b7280;margin-bottom:9px;">'
          + 'Every kilometre below is a stretch between two readings of this machine — '
          + 'they add up to the ' + flNum(d.month_km === null ? (t.total || 0) : d.month_km)
          + ' km shown above.</div>'
        : '<div style="font-size:11.5px;color:#b45309;margin-bottom:9px;">'
          + '⚠ These rows come to ' + flNum(t.total || 0) + ' km but the month figure is '
          + (d.month_km === null ? '—' : flNum(d.month_km))
          + ' km. A meter reading in this month looks wrong.</div>';

    // ⭐⭐ ONE CARD PER DATE (owner review, Aug-13). The old list printed a block
    //    per RIDER, so a handover date arrived as two fragments and the manager had
    //    to assemble the story. A date is one thing that happened to one machine:
    //    it opens on a meter, things occur, it closes on a meter.
    //
    //    The server decides the ORDER (meters and handovers by clock, claims by
    //    where their odometer places them), so this is a dumb renderer and mobile
    //    can show the identical sequence from the identical payload.
    // ⚠ Falls back to the old flat day list when day_cards is absent.
    let hidden = 0;
    const cards = d.day_cards || null;
    const rows = cards ? cards.map(c => {
        const s = c.summary || {};
        if (!(c.lines || []).length) { hidden++; return ''; }

        const head = flvCardVerdict(s);
        const body = c.lines.map(l => flvCardLine(l, s)).join('');

        return '<div class="fl-dc">'
            + '<div class="fl-dc-h"><span>' + flvDate(c.date) + '</span>' + head + '</div>'
            + '<div class="fl-dc-b">' + body + '</div></div>';
    }).join('') : (d.days || []).map(day => flvLegacyDayRow(day, () => hidden++)).join('');

    return strip + note + flvWhoRodeIt(d)
        + (rows || '<div style="font-size:12px;color:#9ca3af;">No readings for this machine this month.</div>')
        + (hidden ? '<div style="font-size:11.5px;color:#9ca3af;margin-top:7px;">· ' + hidden
            + ' other day' + (hidden === 1 ? '' : 's') + ' with nothing recorded for this machine</div>' : '');
}

/**
 * The day's verdict, right-aligned in the card header. This is the whole question a
 * manager opens the list to answer: how far did it go, and does that distance belong
 * to one man or to neither?
 */
function flvCardVerdict(s) {
    const km = s.km === null || s.km === undefined ? null : flNum(s.km);
    const who = (s.riders || []).filter(Boolean).join(' + ');

    if (s.kind === 'shared') {
        return '<span style="color:#0f766e;" title="The meter was started by one rider and closed by '
            + 'another, so this day cannot be split between them — it is charged to neither. '
            + 'These kilometres are counted ONCE on the machine, never twice.">'
            + km + ' km · 🔁 shared' + (who ? ' <span style="font-weight:400;color:#6b7280;">' + flEsc(who) + '</span>' : '')
            + '</span>';
    }
    if (s.kind === 'split') {
        const parts = (s.parts || []).map(p => flNum(p.km) + ' km ' + flEsc(p.who || '—')).join(' + ');
        return '<span style="color:#15803d;" title="A handover odometer was recorded, so this day '
            + 'splits exactly between the two riders.">✂ ' + (parts || km + ' km') + '</span>';
    }
    if (km !== null) {
        return '<span>' + km + ' km on duty'
            + (who ? ' <span style="font-weight:400;color:#6b7280;">' + flEsc(who) + '</span>' : '') + '</span>';
    }
    const TEXT = {
        no_meter: '⚠ meter nahi mila', leave: 'on leave', claim_only: 'sirf claims',
        half: 'aik hi meter', in_progress: 'din band nahi hua',
    };
    return '<span style="font-weight:400;color:#9ca3af;">' + (TEXT[s.kind] || '—') + '</span>';
}

/**
 * ONE LINE OF THE DAY. Labels are English on purpose ("meter start" / "meter end" —
 * owner ruling); only the EXPLANATIONS are Roman Urdu, and the long-form English
 * lives in the tooltip so nothing is lost for whoever wants it.
 */
function flvCardLine(l, s) {
    const K = v => '<span class="fl-dc-k">' + v + '</span>';

    if (l.type === 'meter_start' || l.type === 'meter_end') {
        const isStart = l.type === 'meter_start';
        return '<div class="fl-dc-l">' + K(isStart ? 'meter start' : 'meter end')
            + '<b>' + flNum(l.value) + '</b>'
            + '<span class="fl-muted">· ' + flEsc(l.who || '—') + (l.at ? ' · ' + l.at : '') + '</span>'
            + (isStart && l.source === 'manager'
                ? ' <span class="fl-vchip unk" title="Entered by a manager, not by the rider">✎ manager ne likha</span>'
                : '')
            + '</div>';
    }

    if (l.type === 'claim') {
        return '<div class="fl-dc-l">' + K(flEsc(l.kind))
            + '<span>Rs <b>' + flNum(l.amount) + '</b>'
            + (l.meter ? ' <span class="fl-muted">· meter ' + flNum(l.meter) + '</span>' : '')
            + ' <span class="fl-muted">· ' + flEsc(l.who || '—') + '</span></span>'
            + (l.pending ? ' <span class="fl-vchip due">waiting</span>' : '')
            + '</div>';
    }

    if (l.type === 'handover') {
        return '<div class="fl-dc-l" style="color:#0f766e;">' + K('🔁 handover')
            + '<span>→ <b>' + flEsc(l.to || '—') + '</b>'
            + (l.by_name ? ' <span class="fl-muted">· ' + flEsc(l.by_name) + ' ne record ki</span>' : '')
            + (l.time ? ' <span class="fl-muted">· ' + l.time + '</span>'
                      : (l.recorded_on ? ' <span class="fl-muted">· ' + flvDate(l.recorded_on) + ' ko likha</span>' : ''))
            + (l.meter ? ' <span class="fl-muted">· handover meter ' + flNum(l.meter) + '</span>' : '')
            + '</span></div>';
    }

    // The stretch that ARRIVED at this day — never the day's own run (the server
    // keeps shared/split out of here so no kilometre is shown twice).
    if (l.type === 'gap') {
        const since = l.since ? ' · ' + flvDate(l.since) + ' se' : '';
        if (l.kind === 'transfer') {
            return '<div class="fl-dc-l" style="color:#0f766e;">' + K('🔁 transit')
                + '<span><b>+' + flNum(l.km) + ' km</b>'
                + (l.from && l.to ? ' <span class="fl-muted">· ' + flEsc(l.from) + ' → ' + flEsc(l.to) + '</span>' : '')
                + ' <span class="fl-muted">· kisi ke naam nahi</span></span></div>';
        }
        if (l.kind === 'unaccounted') {
            return '<div class="fl-dc-l" style="color:#b91c1c;">' + K('⚠ unaccounted')
                + '<span title="This stretch spans a day worked with no usable reading, so it cannot be '
                + 'split into work and commute."><b>+' + flNum(l.km) + ' km</b>'
                + '<span class="fl-muted">' + since + ' · beech mein aik din meter ke baghair</span></span></div>';
        }
        return '<div class="fl-dc-l" style="color:#b45309;">' + K('↳ off duty')
            + '<span><b>+' + flNum(l.km) + ' km</b>'
            + '<span class="fl-muted">' + since + (l.who ? ' (' + flEsc(l.who) + ')' : '') + '</span></span></div>';
    }

    return '';
}

/** The pre-day-card row, kept so an older server still renders the list it sends. */
function flvLegacyDayRow(day, countHidden) {
    const interesting = day.work_km !== null || day.gap_km || day.home_km
        || (day.claims && day.claims.length) || day.anomaly || day.partial
        || day.status === 'no_meter';
    if (!interesting) { countHidden(); return ''; }

    let km = '';
    if (day.work_km !== null) {
        km += '<div style="font-size:12.5px;color:#374151;"><b>' + flNum(day.meter_start)
            + ' → ' + flNum(day.meter_end) + '</b> · <b>' + flNum(day.work_km) + ' km</b> on duty</div>';
    }
    if (day.gap_km) {
        km += '<div style="font-size:12px;color:#b45309;">↳ <b>' + flNum(day.gap_km) + ' km</b> '
            + flEsc(day.gap_kind || 'off duty') + '</div>';
    }
    const claims = (day.claims || []).map(c =>
          '<div style="font-size:11.5px;color:#6b7280;margin-left:10px;">'
        + flEsc(c.kind) + ' · <b style="color:#374151;">Rs ' + flNum(c.amount) + '</b>'
        + (c.meter ? ' · meter ' + flNum(c.meter) : '')
        + ' · by ' + flEsc(c.by_name || '—') + '</div>').join('');

    return '<div style="padding:7px 0;border-bottom:1px solid #f3f4f6;">'
        + '<div style="display:flex;align-items:baseline;gap:8px;margin-bottom:2px;">'
        + '<b style="font-size:12.5px;color:#111827;min-width:74px;">' + flvDate(day.date) + '</b>'
        + '<span style="font-size:11.5px;color:#6b7280;">' + flEsc(day.keeper || '—') + '</span>'
        + '</div>' + km + claims + '</div>';
}

/**
 * ⭐⭐ WHO RODE IT THIS MONTH — the machine's mirror of the rider view's "his
 *     machines" strip, from the SAME engine, so the two lenses cannot disagree.
 *
 * Shared and transit kilometres get their own line at the bottom rather than being
 * divided between the riders: the whole point is that they belong to neither.
 */
function flvWhoRodeIt(d) {
    const r = d.riders;
    if (!r || !(r.riders || []).length) return '';

    const rows = r.riders.map(p => {
        const spend = (p.fuel_rs || 0) + (p.maint_rs || 0);
        return '<div class="fl-mrow" style="margin-bottom:4px;">'
            + '<span class="fl-mlink" style="min-width:110px;" onclick="flOpenRider(' + p.user_id + ')" '
            +   'title="Open this rider\'s own month">' + flEsc(p.name || '—') + ' ▸</span>'
            + '<span class="fl-mkm">' + flNum(p.work_km) + ' km</span>'
            + '<span style="color:#6b7280;font-size:11.5px;">on duty</span>'
            + (p.offduty_km ? '<span style="color:#b45309;font-size:11.5px;">+' + flNum(p.offduty_km) + ' off duty</span>' : '')
            + (p.shared_days ? '<span style="color:#0f766e;font-size:11.5px;">🔁 ' + p.shared_days
                + ' handover day' + (p.shared_days === 1 ? '' : 's') + '</span>' : '')
            + (spend > 0 ? '<span style="font-size:11.5px;">Rs ' + flNum(spend) + ' filed</span>' : '')
            + '</div>';
    }).join('');

    const nobody = (r.shared_km || r.transfer_km)
        ? '<div class="fl-mrow" style="background:#e6f7f3;border-color:#c8e9e1;">'
          + '<span class="fl-pill fl-shared">🔁 nobody\'s</span>'
          + (r.shared_km ? '<span class="fl-mkm">' + flNum(r.shared_km) + ' km</span>'
              + '<span style="color:#6b7280;font-size:11.5px;">shared on handover days</span>' : '')
          + (r.transfer_km ? '<span class="fl-mkm">' + flNum(r.transfer_km) + ' km</span>'
              + '<span style="color:#6b7280;font-size:11.5px;">in transit between riders</span>' : '')
          + '</div>'
        : '';

    return '<h4 style="margin:12px 0 7px;">Who rode it this month</h4>' + rows + nobody;
}

/** MACHINE → RIDER, the reverse of flOpenVehicle. */
function flOpenRider(userId) {
    if (!userId) return;
    flSetMode('riders');
    let tries = 0;
    (function open() {
        if (document.getElementById('flRow' + userId)) {
            flSelectRider(userId);
            const row = document.getElementById('flRow' + userId);
            if (row && row.scrollIntoView) row.scrollIntoView({block: 'center'});
        } else if (tries++ < 40) {
            setTimeout(open, 100);
        }
    })();
}

function flvCloseDetail() {
    flvOpenId = null;
    document.getElementById('flVehDetail').style.display = 'none';
}

function flvRenderDetail(v, canManage, res) {
    // Recording condition is a WIDER right than assigning (see canAddPhotos on the
    // server): a bike-service manager may photograph a machine he cannot reassign.
    // Falls back to canManage so an older payload still behaves as before.
    const canAddPhotos = (res && res.can_add_photos !== undefined)
        ? !!res.can_add_photos
        : !!canManage;
    const s = v.service || {};
    const icon = v.vtype === 'van' ? '🚚' : '🏍️';

    /* ⭐ THIS MACHINE'S fuel & maintenance for the month — the same
       `claimsForVehicle` the rider's own screen reads, so a manager and a rider
       can never see two different histories for one bike. Each row names WHO
       filed it, which is what stops a new keeper mistaking a predecessor's
       spend for his own. Rows attributed by assignment window (rather than a
       stamped vehicle_id, which only exists from Aug-2026) are marked, so the
       reconstruction never pretends to be a record. */
    const cl  = (res && res.claims) || [];
    const tot = (res && res.claims_total) || {};
    /* The bike's Rs/km — this month vs the previous 3 pooled (same server
       method the rider's screen reads, so the two can never disagree). */
    const av  = (res && res.averages) || {};
    const ks  = (res && res.keeper_stint) || null;
    /* ⭐ The rider-vs-machine diagnostic (owner ruling Aug-4): the current
       keeper's stint against the bike's own last-3-months baseline. */
    let avLine = '';
    if (ks && ks.rs_per_km) {
        avLine += '<div style="font-size:12px;color:#0f766e;margin-bottom:3px;">'
                + '👤 <b>' + flEsc(ks.name || 'Keeper') + ': Rs ' + ks.rs_per_km + '/km</b>'
                + ' over ' + flNum(ks.km) + ' km since ' + flvDate(ks.since)
                + ((av.last3 && av.last3.rs_per_km)
                    ? ' · this bike\'s usual is Rs ' + av.last3.rs_per_km + '/km' : '')
                + '</div>';
    } else if (av.last3 && av.last3.rs_per_km) {
        avLine += '<div style="font-size:12px;color:#0f766e;margin-bottom:3px;">'
                + 'This bike\'s usual: <b>Rs ' + av.last3.rs_per_km + '/km</b> (last 3 months)</div>';
    }
    if (av.this_month && av.this_month.rs_per_km) {
        avLine += '<div style="font-size:12px;color:#6b7280;margin-bottom:6px;">'
                + '⛽ Rs ' + av.this_month.rs_per_km + '/km this month ('
                + flNum(av.this_month.km) + ' km)</div>';
    }
    const money = cl.length
        ? '<div style="font-size:12.5px;color:#374151;margin-bottom:7px;">'
          + '⛽ <b>Rs ' + flNum(tot.fuel_rs || 0) + '</b> fuel · 🔧 <b>Rs '
          + flNum(tot.maint_rs || 0) + '</b> maintenance</div>'
          + cl.map(c =>
              '<div class="fl-vhrow">'
            +   '<span style="min-width:118px;">' + flEsc(c.kind) + '</span>'
            +   '<span style="min-width:88px;color:#6b7280;">' + flvDate(c.date) + '</span>'
            +   '<span style="min-width:92px;"><b>Rs ' + flNum(c.amount) + '</b></span>'
            +   '<span style="color:#6b7280;">by ' + flEsc(c.by_name || '—')
            +     (c.meter ? ' · meter ' + flNum(c.meter) : '') + '</span>'
            +   (c.is_pending ? '<span class="fl-vchip due">waiting</span>' : '')
            +   (c.stamped ? '' : '<span class="fl-vchip unk" title="Attributed from who held the '
                + 'bike on that date — filed before claims recorded a vehicle">from history</span>')
            + '</div>').join('')
        : '<div style="font-size:12px;color:#9ca3af;">Nothing filed for this machine this month.</div>';

    /* ⭐ Two halves of the same month (owner ruling Aug-5): what it COST, and where
       the kilometres behind that cost went. The diagnostic line above the toggle
       belongs to both, so it stays put when the manager switches. */
    const modeBtn = (m, label) =>
        '<button type="button" class="fl-vbtn" style="padding:3px 9px;font-size:11.5px;'
      + (flvDetailMode === m ? 'background:#fff7ed;border-color:#fdba74;color:#9a3412;font-weight:700;' : '')
      + '" onclick="flvSetDetailMode(\'' + m + '\')">' + label + '</button>';
    const spend = avLine
        + '<div style="display:flex;gap:6px;margin:0 0 9px;">'
        + modeBtn('money', '💰 Fuel &amp; maintenance') + modeBtn('days', '📅 Day by day')
        + '</div>'
        + (flvDetailMode === 'days' ? flvDaysHtml(res) : money);

    const photos = (v.photos || []).length
        ? '<div class="fl-vstrip">' + v.photos.map(p =>
              '<div class="fl-vpic">'
            +   '<img src="' + flEsc(p.url) + '" onclick="flPhoto(\'' + flEsc(p.url) + '\')" alt="">'
            +   '<div class="fl-vpiclab">' + flEsc(p.label) + '<br>' + flvDate(p.taken_on)
            +     (p.note ? '<br><span style="color:#9ca3af;">' + flEsc(p.note) + '</span>' : '')
            +   '</div>'
            + '</div>').join('') + '</div>'
        : '<div style="font-size:12px;color:#9ca3af;">No photos yet. Add them when the vehicle changes hands, '
          + 'so its condition on the day is on record.</div>';

    const history = (v.history || []).length
        ? v.history.map(h =>
              '<div class="fl-vhrow">'
            +   '<span style="min-width:150px;"><b>' + flEsc(h.rider_name || ('user ' + h.user_id)) + '</b></span>'
            +   '<span style="color:#6b7280;">' + flvDate(h.assigned_on) + ' → '
            +     (h.released_on ? flvDate(h.released_on) : 'now') + '</span>'
            +   (h.is_current ? '<span class="fl-vhnow">current</span>' : '')
            +   (h.note ? '<span style="color:#9ca3af;font-size:11.5px;">' + flEsc(h.note) + '</span>' : '')
            + '</div>').join('')
        : '<div style="font-size:12px;color:#9ca3af;">Never assigned.</div>';

    document.getElementById('flVehDetail').innerHTML = ''
      + '<div class="fl-vdhead">'
      +   '<div style="font-size:16px;font-weight:700;color:#111827;">' + icon + ' ' + flEsc(v.name) + '</div>'
      +   '<span class="fl-vtag ' + (v.is_company ? 'co' : 'own') + '">' + (v.is_company ? 'company' : 'own') + '</span>'
      +   '<div style="font-size:12.5px;color:#6b7280;">'
      +     (v.keeper_name ? 'with <b style="color:#111827;">' + flEsc(v.keeper_name) + '</b>' : 'unassigned')
      +     (v.current_meter !== null ? ' · ' + flNum(v.current_meter) + ' km' : '')
      +   '</div>'
      +   '<button type="button" class="fl-vbtn" style="margin-left:auto;" onclick="flvCloseDetail()">Close</button>'
      + '</div>'
      + '<div class="fl-vdbody">'
      +   '<div class="fl-vsec">'
      +     '<h4>Condition</h4>' + photos
      /* ⭐ The condition record is DATED (owner ask, Aug-2026): these photos exist to
         prove what a machine looked like on the day it changed hands, and a handover
         is often photographed later than it happened. Until now the upload sent no
         date at all, so every photo was silently stamped TODAY — which quietly makes
         the record useless for the one question it exists to answer.
         Defaults to today, cannot be set in the future (the server clamps too). */
      /* ⚠ canAddPhotos, NOT canManage — recording condition is its own right, so a
         bike-service manager can photograph a machine he may not reassign. */
      +     (canAddPhotos
          ? '<div style="margin-top:9px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            + '<input type="file" id="flvMorePhotos" accept="image/*" multiple style="font-size:12px;">'
            + '<label style="font-size:11.5px;color:#6b7280;">Taken on '
            +   '<input type="date" id="flvMoreDate" max="' + flvTodayYmd() + '" value="' + flvTodayYmd() + '" '
            +     'style="border:1px solid #d1d5db;border-radius:6px;padding:4px 6px;font-size:12px;margin-left:4px;">'
            + '</label>'
            + '<input type="text" id="flvMoreNote" maxlength="255" placeholder="note (optional)" '
            +   'style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:12px;min-width:190px;">'
            + '<button type="button" class="fl-vbtn" onclick="flvUploadMore(' + v.id + ')">Add photos</button>'
            + '</div>'
          : '')
      +   '</div>'
      +   '<div class="fl-vsec">'
      +     '<h4>Service</h4>'
      +     '<div style="font-size:12.5px;color:#374151;line-height:1.7;">'
      /* ⚠ One job's story, named — the most urgent scheduled job's own last-done and
         interval, not necessarily the bike's most recent visit. The full per-type
         truth sits right below in flvScheduleHtml. */
      +       (s.due_type_name ? '<b>' + flEsc(s.due_type_name) + '</b> — due' : 'Due')
      +       ' every <b>' + flNum(s.interval_km) + ' km</b>'
      +       (s.last_service_meter !== null && s.last_service_meter !== undefined
              ? ' · last done at <b>' + flNum(s.last_service_meter) + ' km</b>'
                + (s.last_service_at ? ' on ' + flvDate(s.last_service_at) : '')
              : ' · <span style="color:#b45309;">no service recorded yet</span>')
      +       (s.due_in_km !== null && s.due_in_km !== undefined
              ? (s.due_in_km < 0
                  ? ' · <b style="color:#991b1b;">' + flNum(-s.due_in_km) + ' km overdue</b>'
                  : ' · due in <b>' + flNum(s.due_in_km) + ' km</b>')
              : '')
      +     '</div>'
      +     flvScheduleHtml(res)
      +     flvServiceHistoryHtml(res)
      +   '</div>'
      +   '<div class="fl-vsec">'
      +     '<h4>This month</h4>' + spend
      +   '</div>'
      +   '<div class="fl-vsec">'
      +     '<h4>Who has had it</h4>' + history
      +   '</div>'
      + '</div>';
}

/**
 * ⭐ THE PER-TYPE SERVICE SCHEDULE ON THE MACHINE (owner ask, Aug-6): oil every
 * 1,200, oil+tuning every 2,500, brake shoe every 10,000 — each with its own
 * "last done / due in", exactly what the rider drill-down has shown for weeks,
 * but keyed to the BIKE. The work travels with the machine now, so "brake shoe
 * never recorded" must not reset just because the keeper changed.
 *
 * Sorted most-urgent first: overdue → due soon → ok → never recorded. The jobs
 * that need money now are the reason a manager opens this at all.
 */
/**
 * ⭐ THE MACHINE'S SERVICE RECORD (owner ask, Aug-13).
 *
 * "When was this bike last serviced, and what was done?" used to be answerable only
 * from the RIDER's drill-down, which meant a handover split one bike's history
 * across two people. This is the same list, attributed to the machine — so the work
 * travels with the bike, which is the whole premise of the registry.
 */
function flvServiceHistoryHtml(res) {
    const h = (res && res.service_history) || [];
    if (!h.length) return '';

    return '<h4 style="margin:14px 0 6px;">Past services</h4>'
        + h.map(s =>
            '<div class="fl-vhrow">'
          +   '<span style="min-width:120px;">' + flvDate(s.date) + '</span>'
          +   '<span style="flex:1;min-width:0;">' + flEsc(s.kind || 'Maintenance')
          +     (s.meter ? ' <span style="color:#9ca3af;">at ' + flNum(s.meter) + ' km</span>' : '')
          +     (s.by_name ? ' <span style="color:#9ca3af;">· by ' + flEsc(s.by_name) + '</span>' : '')
          +     (s.status === 'pending' ? ' <span class="fl-vchip due">waiting</span>' : '')
          +     (s.stamped === false
                  ? ' <span class="fl-vchip unk" title="Attributed by who held this machine on that date, '
                    + 'rather than recorded against it at the time">from history</span>' : '')
          +   '</span>'
          +   '<span>Rs ' + flNum(s.amount) + '</span>'
          + '</div>').join('');
}

function flvScheduleHtml(res) {
    const sched = (res && res.service_schedule) || [];
    if (!sched.length) return '';

    const rank = { overdue: 0, due_soon: 1, ok: 2, unknown: 3 };
    const rows = sched.slice().sort((a, b) =>
        (rank[a.state] ?? 9) - (rank[b.state] ?? 9)
        || (a.due_in_km ?? 1e9) - (b.due_in_km ?? 1e9));

    const chip = t => {
        if (t.state === 'overdue')  return '<span class="fl-vchip over">' + flNum(-t.due_in_km) + ' km overdue</span>';
        if (t.state === 'due_soon') return '<span class="fl-vchip due">due in ' + flNum(t.due_in_km) + ' km</span>';
        if (t.state === 'ok')       return '<span class="fl-vchip ok">due in ' + flNum(t.due_in_km) + ' km</span>';
        return '<span class="fl-vchip unk">never recorded</span>';
    };

    return '<div class="fl-vsched">'
        + rows.map(t => ''
            + '<div class="fl-vschedrow">'
            +   '<div style="min-width:0;">'
            +     '<b>' + flEsc(t.name) + '</b>'
            +     ' <span style="color:#9ca3af;">every ' + flNum(t.interval_km) + ' km</span>'
            +     (t.last_meter !== null
                    ? '<div style="font-size:11px;color:#6b7280;">last at ' + flNum(t.last_meter) + ' km'
                      + (t.last_at ? ' · ' + flvDate(t.last_at) : '')
                      + (t.last_by ? ' · by ' + flEsc(t.last_by) : '')
                      /* ⭐ Say WHY a job nobody filed reads as freshly done — a bigger
                         service contained it (the covers rule). Without this line an
                         Oil Change with no record of its own looks like a bug. */
                      + (t.covered_by ? ' · <i title="A larger scheduled service '
                          + 'includes this job, so it reset this countdown too">done with '
                          + flEsc(t.covered_by) + '</i>' : '')
                      + (t.assumed ? ' <span class="fl-vchip unk" title="Recorded before the registry '
                          + 'knew who had this machine — credited to its first known keeper">assumed</span>' : '')
                      + '</div>'
                    : '')
            +   '</div>'
            +   '<div style="margin-left:auto;flex-shrink:0;">' + chip(t) + '</div>'
            + '</div>').join('')
        + '</div>';
}

// ── assign ────────────────────────────────────────────────────────────────
function flvOpenAssign(vehicleId, preselectUserId) {
    const v = (flvData.vehicles || []).find(x => x.id === vehicleId);
    if (!v) return;

    document.getElementById('flvAssignVehicleId').value = vehicleId;
    document.getElementById('flvAssignTitle').textContent =
        (v.keeper_user_id ? 'Reassign ' : 'Assign ') + v.name;
    document.getElementById('flvAssignNote').value = '';
    document.getElementById('flvAssignPhotos').value = '';
    const mEl0 = document.getElementById('flvAssignMeter');
    if (mEl0) { mEl0.value = ''; flvMeterHint(); }
    document.getElementById('flvAssignError').style.display = 'none';
    document.getElementById('flvPreviewBox').innerHTML = '';
    flvClearDisplaced();

    // Local date, never toISOString() — that reads as yesterday before 5am PKT.
    const d = new Date();
    document.getElementById('flvAssignDate').value =
        d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    // When the flow started from a RIDER ("give him a bike") he is preselected and
    // the machine is the thing being chosen — the reverse of the usual direction.
    const want = preselectUserId || v.keeper_user_id;
    const sel = document.getElementById('flvAssignRider');
    sel.innerHTML = '<option value="">— choose a rider —</option>'
        + (flvData.riders || []).map(r =>
            '<option value="' + r.user_id + '"' + (r.user_id === want ? ' selected' : '') + '>'
          + flEsc(r.name) + (r.company_bike ? ' · 🏢' : ' · 👤') + (r.has_vehicle ? '' : ' · free')
          + '</option>').join('');

    // Which machine, when the manager came in from a rider card. Same filter as
    // flvAssignToRider: company machines + HIS own bike, never someone else's.
    const vsel = document.getElementById('flvAssignVehicleSel');
    if (vsel) {
        const spare = (flvData.vehicles || []).filter(x => !x.keeper_user_id && x.is_active
            && (x.is_company || x.last_keeper_user_id === preselectUserId));
        if (preselectUserId && spare.length > 1) {
            vsel.parentElement.style.display = '';
            vsel.innerHTML = spare.map(x =>
                '<option value="' + x.id + '"' + (x.id === vehicleId ? ' selected' : '') + '>'
              + flEsc(x.name) + (x.is_company ? ' · company' : ' · own') + '</option>').join('');
        } else {
            vsel.parentElement.style.display = 'none';
        }
    }

    document.getElementById('flvAssignModal').style.display = 'flex';
    if (sel.value) flvPreviewAssign();
}

/** The machine picker only appears in the rider-first flow; keep it in step. */
function flvAssignVehicleChanged() {
    const vsel = document.getElementById('flvAssignVehicleSel');
    if (!vsel || !vsel.value) return;
    document.getElementById('flvAssignVehicleId').value = vsel.value;
    flvPreviewAssign();
}

function flvCloseAssign() { document.getElementById('flvAssignModal').style.display = 'none'; }

/**
 * Ask the SERVER what this handover would do. Deliberately not computed here —
 * the answer depends on who currently holds what and whose home pin is missing,
 * and a stale guess in the browser would be worse than no preview at all.
 */
function flvPreviewAssign() {
    const vid  = document.getElementById('flvAssignVehicleId').value;
    const uid  = document.getElementById('flvAssignRider').value;
    const date = document.getElementById('flvAssignDate').value;
    const box  = document.getElementById('flvPreviewBox');
    if (!vid || !uid) { box.innerHTML = ''; return; }

    const seq = ++flvPreviewSeq;
    box.innerHTML = '<span style="color:#9ca3af;">Checking…</span>';

    fetch(FLV_BASE + '/' + vid + '/preview-assign?user_id=' + encodeURIComponent(uid)
          + '&date=' + encodeURIComponent(date), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (seq !== flvPreviewSeq) return;   // a newer preview already answered
            const lines = (res.lines || []).map(l =>
                '<div style="margin-bottom:3px;">• ' + flEsc(l) + '</div>').join('');
            const warns = (res.warnings || []).map(w =>
                '<div style="margin-top:6px;padding:7px 9px;border-radius:7px;background:#fffbeb;'
              + 'border:1px solid #fcd34d;color:#78350f;">⚠ ' + flEsc(w) + '</div>').join('');
            box.innerHTML = (lines || warns)
                ? '<div style="padding:9px 11px;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb;color:#374151;">'
                  + lines + warns + '</div>'
                : '';
            flvRenderDisplaced(res.displaced);
        })
        .catch(() => { if (seq === flvPreviewSeq) box.innerHTML = ''; });
}

/* ═══════════════════════════════════════════════════════════════════════════
   ⭐⭐ "AND WHAT ABOUT HIM?" — the displaced rider.
   `flvDisplaced` holds the manager's answer, sent with the handover.
   ⭐ OWNER RULING (Aug-8): his own registered bike, when free, is the DEFAULT —
   preselected here AND applied server-side even when nothing is sent (older
   clients). The dangerous silent option ("no bike") still requires an explicit
   pick; when he has no own bike the manager must still choose.
   ═══════════════════════════════════════════════════════════════════════════ */
let flvDisplaced = { user_id: null, action: null, vehicle_id: null };
let flvDisplacedData = null;      // the server's description of who is losing it

function flvClearDisplaced() {
    flvDisplaced = { user_id: null, action: null, vehicle_id: null };
    flvDisplacedData = null;
    const box = document.getElementById('flvDisplacedBox');
    if (box) box.style.display = 'none';
}

function flvRenderDisplaced(d) {
    const box = document.getElementById('flvDisplacedBox');
    if (!box) return;
    if (!d) { flvClearDisplaced(); return; }

    // A new person to ask about → reset. ⭐ OWNER RULING (Aug-8): when his own
    // registered bike is free, "back on his own bike" is the DEFAULT — the server
    // applies it even if nothing is sent. "No bike" stays an explicit choice.
    if (flvDisplaced.user_id !== d.user_id) {
        flvDisplaced = { user_id: d.user_id, action: d.own ? 'own' : null, vehicle_id: null };
    }
    flvDisplacedData = d;
    const first = (d.name || '').split(' ')[0];

    document.getElementById('flvDisplacedQ').textContent =
        'And ' + d.name + '? He is giving this machine up — what is he on now?';

    const opt = (action, label, sub) =>
        '<label style="display:flex;gap:7px;align-items:flex-start;cursor:pointer;font-size:12.5px;color:#374151;">'
      + '<input type="radio" name="flvDisp" style="margin-top:2px;"'
      + (flvDisplaced.action === action ? ' checked' : '')
      + ' onchange="flvDisplacedPick(\'' + action + '\')">'
      + '<span><b>' + label + '</b>'
      + (sub ? '<br><span style="color:#92400e;font-size:11.5px;">' + sub + '</span>' : '')
      + '</span></label>';

    let html = '';
    if (d.own) {
        html += opt('own', '👤 Back on his own bike — ' + flEsc(d.own.name),
                    'His own machine is free — this is what happens unless you pick otherwise.');
    }
    if ((d.spare || []).length) {
        html += opt('vehicle', '🏍️ Onto another machine', 'Pick which one below.');
    }
    html += opt('none', '🚫 No bike for now',
                d.goes_quiet
                  ? first + ' will not be asked for meter readings, and cannot file his own fuel, until he has one again.'
                  : 'He will show under “Riders with no machine” on the fleet screen.');

    document.getElementById('flvDisplacedOpts').innerHTML = html;

    const sel = document.getElementById('flvDisplacedVehicle');
    sel.innerHTML = '<option value="">— which machine? —</option>'
        + (d.spare || []).map(s => '<option value="' + s.id + '">' + flEsc(s.name)
            + (s.is_company ? ' · company' : ' · own') + '</option>').join('');
    sel.style.display = flvDisplaced.action === 'vehicle' ? '' : 'none';
    box.style.display = '';
}

function flvDisplacedPick(action) {
    flvDisplaced.action = action;
    const sel = document.getElementById('flvDisplacedVehicle');
    if (action === 'vehicle') {
        sel.style.display = '';
        flvDisplaced.vehicle_id = sel.value || null;
    } else {
        sel.style.display = 'none';
        flvDisplaced.vehicle_id = null;
    }
}

/**
 * ⚠⚠ ADVICE, NOT A GATE. The reading is checked against what we know of this
 *    machine's odometer and the manager is told when it looks wrong — but the
 *    handover is never blocked. A bike that has physically changed hands must be
 *    recordable even when a digit is questionable, or the register starts lying
 *    about who has the machine, which is far worse than one odd number.
 */
function flvMeterHint() {
    const el = document.getElementById('flvAssignMeter');
    const hint = document.getElementById('flvMeterHint');
    if (!el || !hint) return;

    const v = parseInt(el.value, 10);
    if (el.value === '' || isNaN(v)) {
        hint.style.color = '#6b7280';
        hint.innerHTML = 'If you enter it, this day\'s kilometres split exactly between the two riders '
                       + 'instead of showing as shared. Leave blank if you don\'t know it.';
        return;
    }

    const vid = parseInt(document.getElementById('flvAssignVehicleId').value, 10);
    const veh = ((flvData && flvData.vehicles) || []).find(x => x.id === vid);
    const now = veh && veh.current_meter ? parseInt(veh.current_meter, 10) : null;

    if (now !== null && v < now) {
        hint.style.color = '#b45309';
        hint.innerHTML = '⚠ That is <b>below</b> this machine\'s last known reading ('
                       + flNum(now) + ' km). It will still be saved — check for a typo.';
    } else if (now !== null && v - now > 2000) {
        hint.style.color = '#b45309';
        hint.innerHTML = '⚠ That is ' + flNum(v - now) + ' km above the last known reading ('
                       + flNum(now) + ' km). It will still be saved — check for a typo.';
    } else {
        hint.style.color = '#15803d';
        hint.innerHTML = '✓ This day\'s kilometres will be split exactly at ' + flNum(v) + ' km.';
    }
}

function flvSaveAssign() {
    const vid  = document.getElementById('flvAssignVehicleId').value;
    const uid  = document.getElementById('flvAssignRider').value;
    const err  = document.getElementById('flvAssignError');
    const btn  = document.getElementById('flvAssignSave');
    if (!uid) { err.textContent = 'Choose a rider first.'; err.style.display = ''; return; }

    // ⚠ Somebody is losing this machine and the manager has not said what he is on
    //   now. Refusing here is the whole point — a silent default is what left riders
    //   stranded with no machine and the old rules still judging them.
    if (flvDisplacedData && flvDisplacedData.user_id && !flvDisplaced.action) {
        err.textContent = 'Say what ' + flvDisplacedData.name + ' is on now — that is the question above.';
        err.style.display = ''; return;
    }
    if (flvDisplaced.action === 'vehicle' && !flvDisplaced.vehicle_id) {
        err.textContent = 'Choose which machine he moves onto.';
        err.style.display = ''; return;
    }

    const fd = new FormData();
    fd.append('user_id', uid);
    fd.append('date', document.getElementById('flvAssignDate').value || '');
    fd.append('note', document.getElementById('flvAssignNote').value || '');
    // Optional. Sent only when typed — an empty box must not be read as "0 km".
    const mEl = document.getElementById('flvAssignMeter');
    if (mEl && mEl.value !== '' && !isNaN(parseInt(mEl.value, 10))) {
        fd.append('handover_meter', parseInt(mEl.value, 10));
    }
    if (flvDisplaced.action) {
        fd.append('displaced_action', flvDisplaced.action);
        if (flvDisplaced.vehicle_id) fd.append('displaced_vehicle_id', flvDisplaced.vehicle_id);
    }
    const files = document.getElementById('flvAssignPhotos').files;
    for (let i = 0; i < files.length && i < 8; i++) fd.append('photos[]', files[i]);

    err.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch(FLV_BASE + '/' + vid + '/assign', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flvCloseAssign();
        flvLoad();
        if (flvOpenId === Number(vid)) flvOpen(Number(vid));
    })
    .catch(e => { err.textContent = e.message || 'Could not assign that vehicle.'; err.style.display = ''; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Assign'; });
}

/**
 * Taking a machine back displaces its rider exactly as reassigning does, so it
 * asks the same question — otherwise "Take back" would remain the one door that
 * still leaves a man with nothing and nobody told.
 */
function flvRelease(vehicleId) {
    const v = (flvData.vehicles || []).find(x => x.id === vehicleId);
    if (!v) return;

    fetch(FLV_BASE + '/' + vehicleId + '/preview-release', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => flvDoRelease(v, res.displaced || null))
        .catch(() => flvDoRelease(v, null));   // the prompt is a nicety, never a blocker
}

function flvDoRelease(v, d) {
    let action = null, targetVehicle = null;

    if (d) {
        const first = (d.name || '').split(' ')[0];
        // ⭐ OWNER RULING (Aug-8): his own bike, when free, is option 1 AND the
        //   default — "no bike" must be chosen deliberately, never inherited.
        const choices = []; const keys = [];
        if (d.own) { choices.push('Back on his own bike — ' + d.own.name + ' (the default)'); keys.push('own'); }
        if ((d.spare || []).length) { choices.push('Onto another machine'); keys.push('vehicle'); }
        choices.push('Nothing for now' + (d.goes_quiet
            ? ' (he stops being asked for meter readings)' : ''));
        keys.push('none');

        const menu = choices.map((c, i) => '  ' + (i + 1) + '. ' + c).join('\n');
        const ans = prompt('Take ' + v.name + ' back from ' + d.name + '.\n\n'
            + 'What is ' + first + ' on after this?\n' + menu + '\n\nType a number:', '1');
        if (ans === null) return;                       // cancelled

        const idx = parseInt(ans, 10) - 1;
        if (isNaN(idx) || idx < 0 || idx >= keys.length) { alert('Not one of the options — nothing changed.'); return; }
        action = keys[idx];

        if (action === 'vehicle') {
            const list = d.spare.map((s, i) => '  ' + (i + 1) + '. ' + s.name
                + (s.is_company ? ' (company)' : ' (own)')).join('\n');
            const pick = prompt('Which machine does ' + first + ' move onto?\n' + list + '\n\nType a number:', '1');
            if (pick === null) return;
            const pi = parseInt(pick, 10) - 1;
            if (isNaN(pi) || pi < 0 || pi >= d.spare.length) { alert('Not one of the options — nothing changed.'); return; }
            targetVehicle = d.spare[pi].id;
        }
    } else if (!confirm('Take ' + v.name + ' back from ' + (v.keeper_name || 'its rider') + '?\n\n'
               + 'Its history stays on record; it simply has no rider until you assign it again.')) {
        return;
    }

    const fd = new FormData();
    if (action) {
        fd.append('displaced_action', action);
        if (targetVehicle) fd.append('displaced_vehicle_id', targetVehicle);
    }

    fetch(FLV_BASE + '/' + v.id + '/release', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flvLoad();
        if (flvOpenId === v.id) flvOpen(v.id);
    })
    .catch(e => alert(e.message || 'Could not release that vehicle.'));
}

// ── add / edit ────────────────────────────────────────────────────────────
/** Set by "New own bike for X": the rider it is handed to as soon as it exists. */
let flvPendingAssignUser = null;

function flvOpenEdit(id) {
    // ⚠ Cleared on EVERY open. flvNewOwnBike() sets it right after calling this, so
    //   an abandoned "new own bike" can never hand the NEXT vehicle to that rider.
    flvPendingAssignUser = null;
    const v = id ? (flvData.vehicles || []).find(x => x.id === id) : null;
    document.getElementById('flvEditId').value        = id || '';
    document.getElementById('flvEditTitle').textContent = v ? ('Edit ' + v.name) : 'Add a vehicle';
    document.getElementById('flvEditType').value      = v ? v.vtype : 'bike';
    document.getElementById('flvEditReg').value       = v ? (v.reg_no || '') : '';
    document.getElementById('flvEditNick').value      = v ? (v.nickname || '') : '';
    document.getElementById('flvEditModelName').value = v ? (v.make_model || '') : '';
    // ⚠ The STORED override, never the derived interval. `service.interval_km` is the
    //   due job's own schedule (1,200 from Oil Change even when this bike has no
    //   override at all) — pre-filling from it would turn "follow the company
    //   default" into a hard-coded 1,200 the moment anyone opened and saved this form.
    //   Blank here means blank in the database, which is what "default" is.
    document.getElementById('flvEditInterval').value  =
        v && v.service_interval_override ? v.service_interval_override : '';
    document.getElementById('flvEditCompany').checked = v ? !!v.is_company : true;
    document.getElementById('flvEditActive').checked  = v ? !!v.is_active : true;
    document.getElementById('flvEditLat').value    = v && v.base && v.base.latitude  !== null ? v.base.latitude  : '';
    document.getElementById('flvEditLng').value    = v && v.base && v.base.longitude !== null ? v.base.longitude : '';
    document.getElementById('flvEditRadius').value = v && v.base && v.base.radius_m  !== null ? v.base.radius_m  : '';
    document.getElementById('flvEditError').style.display = 'none';
    document.getElementById('flvEditModal').style.display = 'flex';
}

function flvCloseEdit() { document.getElementById('flvEditModal').style.display = 'none'; }

function flvSaveVehicle() {
    const id  = document.getElementById('flvEditId').value;
    const err = document.getElementById('flvEditError');
    const btn = document.getElementById('flvEditSave');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const body = {
        vtype:      document.getElementById('flvEditType').value,
        reg_no:     document.getElementById('flvEditReg').value.trim(),
        nickname:   document.getElementById('flvEditNick').value.trim(),
        make_model: document.getElementById('flvEditModelName').value.trim(),
        is_company: document.getElementById('flvEditCompany').checked,
        is_active:  document.getElementById('flvEditActive').checked,
        service_interval_km: parseInt(document.getElementById('flvEditInterval').value, 10) || 0
    };

    err.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch(FLV_BASE + (id ? '/' + id : ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        // The base is a second, separate write — it has its own endpoint because
        // clearing it is meaningful (the machine goes back to following its rider).
        const vid = id || (res.vehicle && res.vehicle.id);
        const lat = document.getElementById('flvEditLat').value.trim();
        const lng = document.getElementById('flvEditLng').value.trim();
        const rad = document.getElementById('flvEditRadius').value.trim();
        const hadBase = res.vehicle && res.vehicle.base && res.vehicle.base.has_base;

        // ⭐ "New own bike for [rider]" — hand it over the moment it exists, so the
        //   onboarding path is genuinely ONE step. Chained, not fired alongside:
        //   the vehicle must exist before it can be assigned.
        // ⚠ AWAITED and CHECKED (14 Aug): this used to be fire-and-forget with an
        //   empty catch, so a failed assign showed pure success and the reload
        //   raced it — the manager saw "no bike" and had no idea why. A failure
        //   now says the bike EXISTS and where to finish the job.
        const pending = flvPendingAssignUser;
        flvPendingAssignUser = null;
        let assignP = Promise.resolve();
        if (pending && vid) {
            const afd = new FormData();
            afd.append('user_id', pending);
            assignP = fetch(FLV_BASE + '/' + vid + '/assign', {
                method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: afd
            })
            .then(r => r.json())
            .then(a => {
                if (!a.success) {
                    throw new Error('The bike was created, but could not be handed over: '
                        + (a.message || 'assignment failed') + ' Assign it from its card.');
                }
            })
            .catch(e => {
                throw (e instanceof Error ? e : new Error(
                    'The bike was created, but the handover did not go through. Assign it from its card.'));
            });
        }

        if (!vid || (!lat && !lng && !hadBase)) return assignP;
        return assignP.then(() => fetch(FLV_BASE + '/' + vid + '/base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(
                (!lat || !lng)
                    ? { clear: true }
                    : { latitude: lat, longitude: lng, radius_m: parseInt(rad, 10) || null })
        }).then(r => r.json()).then(b => { if (!b.success) throw new Error(b.message || 'Base not saved'); }));
    })
    .then(() => { flvCloseEdit(); flvLoad(); if (flvOpenId) flvOpen(flvOpenId); })
    .catch(e => { err.textContent = e.message || 'Could not save.'; err.style.display = ''; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Save'; });
}

/** Today as YYYY-MM-DD, LOCAL — never toISOString(), which reads as yesterday
 *  before 5am PKT and would then also block picking today as `max`. */
function flvTodayYmd() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
         + '-' + String(d.getDate()).padStart(2, '0');
}

function flvUploadMore(vehicleId) {
    const input = document.getElementById('flvMorePhotos');
    if (!input || !input.files.length) { alert('Choose one or more photos first.'); return; }

    const fd = new FormData();
    for (let i = 0; i < input.files.length && i < 8; i++) fd.append('photos[]', input.files[i]);
    fd.append('context', 'condition');
    // ⭐ The day the condition was as photographed — not the day it was uploaded.
    const dEl = document.getElementById('flvMoreDate');
    const nEl = document.getElementById('flvMoreNote');
    if (dEl && dEl.value) fd.append('date', dEl.value);
    if (nEl && nEl.value.trim()) fd.append('note', nEl.value.trim());

    fetch(FLV_BASE + '/' + vehicleId + '/photos', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flvOpen(vehicleId);
        flvLoad();
    })
    .catch(e => alert(e.message || 'Could not upload those photos.'));
}

/* ── which machine each day was on, inside the rider drill-down ───────────── */
let flvDayMap = {};          // 'YYYY-MM-DD' -> {vehicle_id, label, overridden, transfer}
let flvDaysCanManage = false;

/**
 * The chip beside a day's date. Silent when there is nothing to say — a fleet
 * with one bike per rider would otherwise repeat the same plate 30 times down
 * the column, which is noise, not information. So it renders only when the day
 * is interesting (a manager correction, or a handover) or when the manager can
 * act on it.
 */
function flvDayChip(uid, date) {
    const d = flvDayMap[date];
    if (!d || !d.label) {
        return flvDaysCanManage
            ? ' <a href="#" class="fl-vdaylink" onclick="flvEditDay(' + uid + ',\'' + date + '\');return false;"'
              + ' title="Record which vehicle he was on">🏍️ set</a>'
            : '';
    }
    let chip = '';
    if (d.overridden) {
        chip = '<span class="fl-vdaychip fix" title="Recorded by a manager for this day">✎ '
             + flEsc(d.label) + '</span>';
    } else if (d.transfer) {
        chip = '<span class="fl-vdaychip xfer" title="This vehicle changed hands on this day — '
             + 'the handover ride is allowed for, not counted as personal use">🔁 ' + flEsc(d.label) + '</span>';
    } else {
        return flvDaysCanManage
            ? ' <a href="#" class="fl-vdaylink" onclick="flvEditDay(' + uid + ',\'' + date + '\');return false;"'
              + ' title="' + flEsc(d.label) + ' — click to correct">' + flEsc(d.label) + '</a>'
            : ' <span class="fl-vdaychip">' + flEsc(d.label) + '</span>';
    }
    return ' ' + (flvDaysCanManage
        ? '<a href="#" style="text-decoration:none;" onclick="flvEditDay(' + uid + ',\'' + date + '\');return false;">'
          + chip + '</a>'
        : chip);
}

/**
 * Correct (or clear) the machine recorded against one day.
 *
 * The vehicle list is fetched on demand rather than assumed: this is reachable
 * from the costs drawer without the Vehicles view ever having been opened, and
 * "open that tab first, then come back" is not an instruction worth giving.
 */
async function flvEditDay(uid, date) {
    let list = ((flvData && flvData.vehicles) || []);
    if (!list.length) {
        try {
            const res = await fetch(FLV_BASE, {headers: {'Accept': 'application/json'}}).then(r => r.json());
            if (res && res.success) { flvData = res; list = res.vehicles || []; }
        } catch (e) { /* falls through to the message below */ }
    }
    if (!list.length) {
        alert('No vehicles are registered yet, so there is nothing to record against this day.');
        return;
    }
    const menu = list.map((v, i) => (i + 1) + ' = ' + v.name).join('\n');
    const ans = window.prompt(
        'Which vehicle was he on for ' + date + '?\n\n' + menu +
        '\n\n0 = follow the normal assignment (clear any correction)',
        '');
    if (ans === null) return;

    const n = parseInt(String(ans).trim(), 10);
    if (isNaN(n) || n < 0 || n > list.length) { alert('Enter a number from the list.'); return; }
    const vehicleId = n === 0 ? null : list[n - 1].id;

    fetch(FLV_BASE + '/day-override', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify({user_id: uid, date: date, vehicle_id: vehicleId})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flSelectRider(uid);      // re-render the drawer with the corrected day
    })
    .catch(e => alert(e.message || 'Could not save that.'));
}

/** "12 Jan 26" — short, unambiguous, and never a US month/day flip. */
function flvDate(d) {
    if (!d) return '';
    const x = new Date(d + 'T12:00:00');
    return isNaN(x) ? d : x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: '2-digit' });
}
</script>
