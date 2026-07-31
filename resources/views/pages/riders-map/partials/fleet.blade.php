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
        <div class="fl-monthwrap">
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
                    <th class="num" title="Meter-out at home → next morning's meter-in. Real commuting only.">Off-duty</th>
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
                <tr><td colspan="11" class="fl-empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <div id="flDetail" class="fl-detail" style="display:none;"></div>
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
      </div>

      <div id="flNewSvcWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">What was done</label>
        <select id="flNewServiceType" onchange="flNewSvcChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
          <option value="">Not a bike / other maintenance</option>
          <option value="oil_change">🛢️ Regular service (oil change / tuning)</option>
          <option value="repair">🔧 Repair (anything broken)</option>
        </select>
      </div>

      <div style="display:flex;gap:10px;">
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Amount (Rs)</label>
          <input id="flNewAmount" type="number" min="1" step="0.01" placeholder="e.g. 1000"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        </div>
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Date</label>
          <input id="flNewDate" type="date"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        </div>
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
    document.getElementById('flBody').innerHTML = '<tr><td colspan="11" class="fl-empty">Loading…</td></tr>';

    fetch(FL_BASE + '?month=' + encodeURIComponent(month) + (fresh ? '&fresh=1' : ''))
        .then(r => r.status === 403 ? Promise.reject(new Error('403')) : r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flData = res;
            flRenderTable(res);
            flRenderVerdict(res.totals);
            flRenderNotes(res);
        })
        .catch(err => {
            const msg = err.message === '403'
                ? 'You do not have permission to see fleet costs.'
                : 'Could not load this month. Please try again.';
            document.getElementById('flBody').innerHTML =
                '<tr><td colspan="11" class="fl-empty">' + msg + '</td></tr>';
            document.getElementById('flVerdict').style.display = 'none';
            document.getElementById('flNotes').style.display = 'none';
        });
}

function flRenderTable(res) {
    const rows = res.riders || [];
    if (!rows.length) {
        document.getElementById('flBody').innerHTML =
            '<tr><td colspan="11" class="fl-empty">No fuel or distance recorded this month.</td></tr>';
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
            '<td>' + flBikePill(r.bike) + '</td>' +
            '<td class="num">' + flNum(r.work_km) + '</td>' +
            '<td class="num">' + (r.offduty_km === null ? '<span class="fl-muted">—</span>' : flNum(r.offduty_km)) +
              // Km inside a stretch that contains a worked-but-unmetered day. Shown
              // right here so the commute figure is never read as including them.
              (r.unattributed_km > 0
                ? '<div class="fl-maintsplit" style="color:#b45309;" title="Kilometres across a stretch that contains a day he worked with no usable meter — part work, part commute, and impossible to split. Not counted as either.">+' + flNum(r.unattributed_km) + ' unattributed</div>'
                : '') + '</td>' +
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

function flBikePill(b) {
    if (b === 'company') return '<span class="fl-pill fl-company">🏢 company</span>';
    if (b === 'own') return '<span class="fl-pill fl-own">👤 own</span>';
    return '<span class="fl-pill fl-unknown">❓ unknown</span>';
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
    const unattKm = (t.company && t.company.unattributed_km) || 0;
    if (unattKm > 0) {
        notes.push('<b>' + flNum(unattKm) + ' km</b> ran across stretches containing a day worked with no meter — ' +
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

    fetch(FL_BASE + '/rider?month=' + encodeURIComponent(flMonth) + '&rider_id=' + uid)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flApproval = res.approval || null;
            flCanManageService = !!res.can_manage_service;
            flDefaultInterval = res.default_interval_km || 0;
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
            km = d.meter_start + ' → ' + d.meter_end + ' · <b>' + d.work_km + ' km</b>';
            if (d.offduty_km !== null && d.offduty_km > 0) {
                km += d.offduty_since
                    ? ' · <span title="Measured from the last usable reading, not yesterday">+' +
                      d.offduty_km + ' km off-duty since ' + flDate(d.offduty_since) + '</span>'
                    : ' · <span title="Ridden since the previous day\'s close">+' + d.offduty_km + ' km off-duty</span>';
            }
            if (d.incl_ride_home) {
                km += ' <span class="fl-muted" title="On duty runs to the meter-out at home">🏠 to home</span>';
            }
        } else {
            // Say WHY. Leave/absent are states, not failures — only a day he
            // actually worked can be "missing" a reading.
            const cls = d.status === 'missing' ? 'fl-daymissing' : 'fl-muted';
            km = '<span class="' + cls + '">' + (FL_DAY_TEXT[d.detail] || FL_DAY_TEXT[d.status] || 'no meter reading') + '</span>';
        }
        return '<div class="fl-day"><div class="fl-dayhead">' +
            '<span class="fl-daydate">' + flDate(d.date) + '</span>' +
            '<span class="fl-daykm">' + km + '</span></div>' + claims + '</div>';
    }).join('');

    const svc = r.service;
    let svcHtml = '<h5>Service</h5>';
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
            '<button class="fl-btn" onclick="flSetBikeInterval(' + r.user_id + ',' +
            (svc && svc.interval_km ? svc.interval_km : 0) + ')">⚙️ This bike\'s schedule</button>' +
            '<button class="fl-btn" onclick="flSetDefaultInterval()">🏢 Company default (' + flNum(flDefaultInterval) + ' km)</button>' +
            '</div>' +
            // The rider's own maintenance request is the normal input — it carries
            // the bill and the photo, and approving it resets the clock by itself.
            // Saying so stops a manager double-recording work already filed.
            '<div style="font-size:11px;color:#9ca3af;margin-top:7px;line-height:1.45;">' +
            'Riders normally record a service by filing a Maintenance request with the meter reading — ' +
            'approving it resets this automatically. Use “Record service” only for work filed no other way.</div>';
    }

    const hist = r.service_history || [];   // approved + pending, filtered server-side
    if (hist.length) {
        svcHtml += '<h5 style="margin-top:14px;">Past services</h5>' + hist.map(h =>
            '<div class="fl-svc"><span>' + flDate(h.date) + (h.type ? ' · ' + flServiceLabel(h.type) : '') +
            (h.status === 'pending' ? ' <span class="fl-pill fl-due">⏳</span>' : '') + '</span>' +
            '<span>Rs ' + flNum(h.amount) + (h.photo ? ' <a href="#" onclick="flPhoto(\'' + h.photo + '\');return false;">📷</a>' : '') + '</span></div>'
        ).join('');
    }

    document.getElementById('flDetail').innerHTML =
        '<div class="fl-dhead"><h4>' + flEsc(r.name) + '</h4>' + flBikePill(r.bike) +
        '<span style="font-size:12px;color:#6b7280;">day by day · ' + flMonthLabel(r.month) + '</span>' +
        '<button class="fl-dclose" onclick="flCloseDetail()" title="Close">&times;</button></div>' +
        '<div class="fl-dbody"><div class="fl-days">' + flKmSummary(r) +
        (days || '<div class="fl-empty">Nothing recorded this month.</div>') +
        '</div><div class="fl-side">' + svcHtml + '</div></div>';
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
    if (row.total_km !== null && row.total_km !== undefined) {
        cells += '<div class="fl-kmcell"><b>' + flNum(row.total_km) + '</b><span>total</span></div>';
    }
    cells += '<div class="fl-kmcell"><b>' + counted + '</b><span>days counted</span></div>';

    let notes = '';
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
    mid += ' <span class="fl-pill ' + (c.kind === 'fuel' ? 'fl-own' : 'fl-company') + '">' +
           (c.kind === 'fuel' ? '⛽ fuel' : '🔧 ' + flServiceLabel(c.service_type)) + '</span>';
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
        const accs = (flApproval.accounts || []).map(a =>
            '<option value="' + a.id + '">' + flEsc(a.account_name) + '</option>').join('');
        actions =
            '<div class="fl-actions" id="flAct' + c.id + '">' +
            (accs ? '<select class="fl-src" id="flSrc' + c.id + '" title="Pay from">' + accs + '</select>' : '') +
            '<button class="fl-approve" onclick="flApprove(' + c.id + ',' + c.next_level + ')">✅ Approve</button>' +
            '<button class="fl-reject" onclick="flReject(' + c.id + ',' + c.next_level + ')">❌ Reject</button>' +
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
    if (sel && sel.value) extra.payment_source_account_id = parseInt(sel.value, 10);
    flClaimAction(id, level, 'approve', extra);
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
    const v = window.prompt(
        'Service every how many km, company-wide?\n\n' +
        'This applies to every bike that has no schedule of its own.\n' +
        'Bikes with their own interval are unaffected.',
        flDefaultInterval || '');
    if (v === null) return;
    const km = parseInt(String(v).replace(/[^0-9]/g, ''), 10);
    if (!km || km < 100 || km > 100000) { alert('Give a value between 100 and 100,000 km.'); return; }

    fetch(FL_BASE + '/default-interval', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify({ interval_km: km })
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flDefaultInterval = res.interval_km;
        alert(res.message);
        flLoad(flMonth);
        if (flSelected) flSelectRider(flSelected);
    })
    .catch(err => alert(err.message || 'Could not save the default.'));
}

/** A service HAPPENED — resets the due clock. Never touches the schedule. */
function flMarkServiced(uid, suggested) {
    const v = window.prompt(
        'Odometer reading at this service (km):\n\nThis records that a regular service was done and resets the due date.',
        suggested || '');
    if (v === null) return;
    const meter = parseInt(String(v).replace(/[^0-9]/g, ''), 10);
    if (!meter || meter < 0) { alert('Enter the odometer reading in kilometres.'); return; }
    flPostService({ rider_id: uid, meter: meter });
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
    flNewCat = cat;
    const isPetrol = cat === 'Petrol';
    document.getElementById('flNewTitle').textContent = isPetrol ? '⛽ New petrol request' : '🔧 New maintenance request';
    document.getElementById('flNewSvcWrap').style.display = isPetrol ? 'none' : 'block';
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
    document.getElementById('flNewDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('flNewDate').max = new Date().toISOString().split('T')[0];
    flNewError('');
    flNewRiderChanged();
    document.getElementById('flNewModal').style.display = 'flex';
}

function flCloseNew() { document.getElementById('flNewModal').style.display = 'none'; }

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

function flNewSvcChanged() {
    const sel = document.getElementById('flNewRider');
    const opt = sel.options[sel.selectedIndex];
    const isCompany = opt && opt.dataset && opt.dataset.company === '1';
    const svc = document.getElementById('flNewServiceType').value;
    // Required on a company bike for petrol, and for a SCHEDULED service (a
    // service with no odometer can never reset the bike's service clock).
    // A repair never needs it.
    const need = isCompany && (flNewCat === 'Petrol' || svc === 'oil_change');
    document.getElementById('flNewMeterReq').textContent = need ? '(required)' : '(optional)';
    document.getElementById('flNewMeterHint').textContent = flNewCat === 'Petrol'
        ? 'The odometer at the moment of filling — this is what links the fill to the km ridden.'
        : (svc === 'oil_change' ? 'The odometer at the service — this resets the bike\'s service-due clock on approval.' : '');
}

function flSubmitNew() {
    const uid    = document.getElementById('flNewRider').value;
    const amount = parseFloat(document.getElementById('flNewAmount').value);
    const date   = document.getElementById('flNewDate').value;
    const meter  = document.getElementById('flNewMeter').value;
    const svc    = document.getElementById('flNewServiceType').value;
    const note   = document.getElementById('flNewNote').value;

    if (!uid) { flNewError('Choose which rider this is for.'); return; }
    if (!amount || amount <= 0) { flNewError('Enter the amount.'); return; }
    if (!date) { flNewError('Choose the date.'); return; }

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
    if (flNewCat === 'Maintenance' && svc) body.service_type = svc;

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
</script>
