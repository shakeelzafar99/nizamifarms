@extends('layouts.app')

@section('content')
<style>
  .pr-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 120px; font-size: 13px; color: #1f2937; }
  .pr-head { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; justify-content: space-between; margin-bottom: 14px; }
  .pr-title { font-size: 22px; font-weight: 700; color: #111827; margin: 0; }
  .pr-sub { color: #6b7280; font-size: 12.5px; margin-top: 3px; }
  .pr-monthbar { display: flex; align-items: center; gap: 8px; }
  .pr-navbtn { width: 32px; height: 34px; border: 1px solid #d1d5db; background: #fff; border-radius: 8px; cursor: pointer; font-size: 17px; line-height: 1; color: #374151; }
  .pr-navbtn:hover { background: #f3f4f6; }
  .pr-month-input { height: 34px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 10px; font-size: 13px; color: #111827; background: #fff; }
  .pr-btn-primary { height: 34px; padding: 0 16px; border: none; border-radius: 8px; background: #16a34a; color: #fff; font-weight: 600; font-size: 13px; cursor: pointer; }
  .pr-btn-primary:hover { background: #15803d; }
  .pr-btn-primary:disabled { background: #9ca3af; cursor: default; }

  .pr-strip { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
  .pr-card { flex: 1 1 150px; min-width: 140px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; background: #fff; }
  .pr-card .k { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; }
  .pr-card .v { font-size: 18px; font-weight: 700; color: #111827; margin-top: 2px; }

  .pr-tablewrap { border: 1px solid #e5e7eb; border-radius: 12px; overflow: auto; background: #fff; }
  table.pr-table { width: 100%; border-collapse: collapse; min-width: 1040px; }
  .pr-table thead th { position: sticky; top: 0; background: #f9fafb; z-index: 1; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
  .pr-table td { padding: 11px 12px; border-bottom: 1px solid #f1f3f5; vertical-align: middle; }
  .pr-table tbody tr:hover { background: #fcfcfd; }
  .pr-table td.num, .pr-table th.num { text-align: right; font-variant-numeric: tabular-nums; }

  .pr-emp-name { font-weight: 600; color: #111827; }
  .pr-emp-sub { font-size: 11px; color: #9ca3af; margin-top: 1px; }

  .pr-base { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; padding: 3px 6px; border-radius: 6px; }
  .pr-base:hover { background: #f3f4f6; }
  .pr-base .amt { font-weight: 600; }
  .pr-base .pen { font-size: 11px; color: #9ca3af; }
  .pr-base-input { width: 96px; height: 30px; border: 1px solid #16a34a; border-radius: 6px; padding: 0 8px; font-size: 13px; text-align: right; }
  .pr-setsal { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border: 1px dashed #f59e0b; color: #b45309; background: #fffbeb; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; }

  .pr-chip { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
  .pr-chip.present { background: #ecfdf5; color: #047857; }
  .pr-chip.absent  { background: #fef2f2; color: #b91c1c; cursor: pointer; }
  .pr-chip.leave   { background: #eff6ff; color: #1d4ed8; cursor: pointer; }
  .pr-chip.late    { background: #fff7ed; color: #c2410c; }
  .pr-chip.ot      { background: #f5f3ff; color: #6d28d9; cursor: pointer; }
  .pr-chip.muted   { background: #f3f4f6; color: #9ca3af; }
  .pr-chip.paid    { background: #ecfdf5; color: #047857; }
  .pr-chip.unpaid  { background: #f3f4f6; color: #6b7280; }
  .pr-flag { display: inline-block; margin-left: 4px; color: #b45309; cursor: help; }

  .pr-late-cell { min-width: 130px; }
  .pr-formula { font-size: 10.5px; color: #9ca3af; margin-top: 3px; }
  .pr-ded-input { width: 88px; height: 28px; border: 1px solid #d1d5db; border-radius: 6px; padding: 0 7px; font-size: 12.5px; text-align: right; margin-top: 4px; }
  .pr-ded-input.override { border-color: #c2410c; background: #fff7ed; }

  .pr-adv { color: #b45309; cursor: pointer; font-weight: 600; }
  .pr-adv.zero { color: #cbd5e1; cursor: default; font-weight: 400; }
  .pr-give { display: inline-block; margin-top: 3px; font-size: 11px; color: #2563eb; cursor: pointer; }
  .pr-give:hover { text-decoration: underline; }
  .pr-dblpay { margin-top: 4px; font-size: 11px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 2px 6px; display: inline-block; }
  .pr-net { font-weight: 700; font-size: 14px; color: #111827; }
  .pr-net.neg { color: #b91c1c; }
  .pr-ded-link { cursor: pointer; border-bottom: 1px dotted #cbd5e1; }
  .pr-ded-link:hover { color: #111827; border-bottom-color: #9ca3af; }

  .pr-empty { padding: 40px; text-align: center; color: #9ca3af; }

  /* sticky pay bar */
  .pr-paybar { position: fixed; left: 0; right: 0; bottom: 0; background: #111827; color: #fff; display: none; align-items: center; gap: 16px; padding: 12px 22px; z-index: 40; box-shadow: 0 -4px 16px rgba(0,0,0,.15); }
  .pr-paybar.show { display: flex; }
  .pr-paybar .sel { font-size: 13px; color: #d1d5db; }
  .pr-paybar .tot { font-size: 17px; font-weight: 700; margin-left: auto; }
  .pr-paybar .pay { height: 38px; padding: 0 20px; border: none; border-radius: 9px; background: #22c55e; color: #06210f; font-weight: 700; font-size: 14px; cursor: pointer; }
  .pr-paybar .pay:hover { background: #16a34a; color: #fff; }

  /* modal */
  .pr-modal-back { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: none; align-items: center; justify-content: center; z-index: 60; padding: 16px; }
  .pr-modal-back.show { display: flex; }
  .pr-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 460px; max-height: 88vh; overflow: auto; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
  .pr-modal-h { padding: 16px 18px; border-bottom: 1px solid #eef0f2; font-size: 16px; font-weight: 700; }
  .pr-modal-b { padding: 16px 18px; }
  .pr-modal-f { padding: 14px 18px; border-top: 1px solid #eef0f2; display: flex; gap: 10px; justify-content: flex-end; }
  .pr-fund { display: block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; cursor: pointer; }
  .pr-fund.active { border-color: #16a34a; background: #f0fdf4; }
  .pr-fund .lbl { font-weight: 600; }
  .pr-bank-sel { width: 100%; height: 36px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 8px; margin-top: 8px; font-size: 13px; }
  .pr-btn-ghost { height: 36px; padding: 0 14px; border: 1px solid #d1d5db; background: #fff; border-radius: 8px; cursor: pointer; font-size: 13px; }
  .pr-paylist { max-height: 180px; overflow: auto; border: 1px solid #f1f3f5; border-radius: 8px; margin-bottom: 14px; }
  .pr-paylist .r { display: flex; justify-content: space-between; padding: 7px 12px; border-bottom: 1px solid #f6f7f8; font-size: 12.5px; }
  .pr-paylist .r:last-child { border-bottom: none; }

  /* generic side sheet for date lists */
  .pr-sheet-back { position: fixed; inset: 0; background: rgba(15,23,42,.4); display: none; z-index: 70; }
  .pr-sheet-back.show { display: block; }
  .pr-sheet { position: fixed; top: 0; right: 0; bottom: 0; width: 340px; max-width: 92vw; background: #fff; box-shadow: -8px 0 24px rgba(0,0,0,.18); transform: translateX(100%); transition: transform .18s ease; overflow: auto; }
  .pr-sheet-back.show .pr-sheet { transform: translateX(0); }
  .pr-sheet-h { padding: 15px 16px; border-bottom: 1px solid #eef0f2; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
  .pr-sheet-x { cursor: pointer; color: #9ca3af; font-size: 20px; line-height: 1; }
  .pr-sheet-b { padding: 8px 4px; }
  .pr-daterow { display: flex; justify-content: space-between; padding: 9px 14px; border-bottom: 1px solid #f6f7f8; font-size: 13px; }
  .pr-daterow .dt { font-weight: 600; color: #111827; }
  .pr-daterow .lb { color: #6b7280; font-size: 12px; }
</style>

<div class="pr-wrap">
  <div class="pr-head">
    <div>
      <h1 class="pr-title">Payroll</h1>
      <div class="pr-sub">Review the month, fix any salary, then pay your team — cash or online.</div>
    </div>
    <div class="pr-monthbar">
      <button class="pr-navbtn" id="prPrev" title="Previous month">‹</button>
      <input type="month" class="pr-month-input" id="prMonth">
      <button class="pr-navbtn" id="prNext" title="Next month">›</button>
      <button class="pr-btn-primary" id="prGen">Generate</button>
    </div>
  </div>

  <div class="pr-strip" id="prStrip"></div>

  <div class="pr-tablewrap">
    <table class="pr-table">
      <thead>
        <tr>
          <th style="width:34px;"><input type="checkbox" id="prSelAll"></th>
          <th>Employee</th>
          <th class="num">Base salary</th>
          <th>Attendance</th>
          <th class="pr-late-cell">Late</th>
          <th>Overtime</th>
          <th class="num">Advances</th>
          <th class="num">Deductions</th>
          <th class="num">Net pay</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="prBody">
        <tr><td colspan="10" class="pr-empty">Pick a month and press <b>Generate</b>.</td></tr>
      </tbody>
    </table>
  </div>
</div>

{{-- sticky pay bar --}}
<div class="pr-paybar" id="prPaybar">
  <span class="sel" id="prPaybarSel">0 selected</span>
  <span class="tot" id="prPaybarTot">Rs 0</span>
  <button class="pay" id="prPayBtn">Pay selected →</button>
</div>

{{-- pay modal --}}
<div class="pr-modal-back" id="prPayModal">
  <div class="pr-modal">
    <div class="pr-modal-h">Pay salaries</div>
    <div class="pr-modal-b">
      <div class="pr-paylist" id="prPayList"></div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Pay from</div>
      <label class="pr-fund active" data-fund="cash">
        <input type="radio" name="prFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-fund="online">
        <input type="radio" name="prFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prBankSel" disabled></select>
      </label>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prPayCancel">Cancel</button>
      <button class="pr-btn-primary" id="prPayConfirm">Confirm payment</button>
    </div>
  </div>
</div>

{{-- give-advance modal --}}
<div class="pr-modal-back" id="prAdvModal">
  <div class="pr-modal">
    <div class="pr-modal-h">Give advance</div>
    <div class="pr-modal-b">
      <div style="font-size:13px;color:#374151;margin-bottom:10px;" id="prAdvWho"></div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">Amount</div>
      <input type="number" id="prAdvAmount" class="pr-bank-sel" style="height:38px;" placeholder="0" min="1">
      <div style="font-size:12px;color:#6b7280;margin:12px 0 4px;">Pay from</div>
      <label class="pr-fund active" data-advfund="cash">
        <input type="radio" name="prAdvFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prAdvCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-advfund="online">
        <input type="radio" name="prAdvFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prAdvBankSel" disabled></select>
      </label>
      <input type="text" id="prAdvNote" class="pr-bank-sel" style="height:36px;margin-top:10px;" placeholder="Note (optional)">
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prAdvCancel">Cancel</button>
      <button class="pr-btn-primary" id="prAdvConfirm">Give advance</button>
    </div>
  </div>
</div>

{{-- date-list side sheet --}}
<div class="pr-sheet-back" id="prSheet">
  <div class="pr-sheet">
    <div class="pr-sheet-h"><span id="prSheetTitle">Details</span><span class="pr-sheet-x" id="prSheetX">×</span></div>
    <div class="pr-sheet-b" id="prSheetBody"></div>
  </div>
</div>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const fmt = (n) => 'Rs ' + Math.round(Number(n) || 0).toLocaleString('en-PK');
  const el = (id) => document.getElementById(id);
  let ROWS = [];        // computed rows from server
  let FUND = { cash: null, banks: [] };
  let CURMONTH = '';

  // ---- month init ----
  const now = new Date();
  const dflt = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
  el('prMonth').value = dflt;

  function shiftMonth(delta) {
    const [y, m] = el('prMonth').value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    el('prMonth').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }
  el('prPrev').onclick = () => { shiftMonth(-1); load(); };
  el('prNext').onclick = () => { shiftMonth(1); load(); };
  el('prGen').onclick = load;
  el('prMonth').onchange = load;

  // ---- load month ----
  async function load() {
    const month = el('prMonth').value;
    el('prGen').disabled = true; el('prGen').textContent = 'Loading…';
    el('prBody').innerHTML = '<tr><td colspan="10" class="pr-empty">Loading…</td></tr>';
    try {
      const res = await fetch('/hr/payroll/data?month=' + encodeURIComponent(month), { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      CURMONTH = j.month;
      FUND = j.funding || { cash: null, banks: [] };
      ROWS = (j.rows || []).map(r => ({ ...r, _selected: false, _lateOverride: null }));
      renderStrip();
      renderRows();
      buildFundModal();
    } catch (e) {
      el('prBody').innerHTML = '<tr><td colspan="10" class="pr-empty">Could not load payroll: ' + (e.message || e) + '</td></tr>';
    } finally {
      el('prGen').disabled = false; el('prGen').textContent = 'Generate';
    }
  }

  // effective late deduction for a row (override wins)
  function lateDed(r) {
    return (r._lateOverride !== null && r._lateOverride !== '') ? Number(r._lateOverride) : Number(r.late_deduction || 0);
  }
  function net(r) {
    const ded = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0);
    return Number(r.base_salary || 0) + Number(r.bonuses || 0) + Number(r.allowances || 0) + Number(r.other || 0) - ded;
  }

  function renderStrip() {
    const configured = ROWS.filter(r => r.configured);
    const totalNet = ROWS.reduce((s, r) => s + Math.max(0, net(r)), 0);
    const missing = ROWS.filter(r => !r.configured).length;
    el('prStrip').innerHTML =
      card('Employees', ROWS.length) +
      card('With salary set', configured.length) +
      card('Total net (month)', fmt(totalNet)) +
      (missing > 0 ? card('Need salary', missing) : '');
  }
  function card(k, v) { return '<div class="pr-card"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }

  function renderRows() {
    if (!ROWS.length) {
      el('prBody').innerHTML = '<tr><td colspan="10" class="pr-empty">No employees for this month.</td></tr>';
      updatePaybar();
      return;
    }
    el('prBody').innerHTML = ROWS.map((r, i) => rowHtml(r, i)).join('');
    // wire per-row interactions
    ROWS.forEach((r, i) => wireRow(r, i));
    updatePaybar();
  }

  function rowHtml(r, i) {
    // base cell
    let baseCell;
    if (r.configured) {
      baseCell = '<span class="pr-base" data-base="' + i + '"><span class="amt">' + fmt(r.base_salary) + '</span><span class="pen">✎</span></span>';
    } else {
      baseCell = '<span class="pr-setsal" data-base="' + i + '">＋ Set salary</span>';
    }

    // attendance
    const att = '<span class="pr-chip present">' + r.present_days + ' present</span> ' +
      (r.absent_days > 0
        ? '<span class="pr-chip absent" data-drill="month_absent" data-uid="' + r.user_id + '">' + r.absent_days + ' absent</span>'
        : '<span class="pr-chip muted">0 absent</span>') +
      (r.leave_days > 0
        ? ' <span class="pr-chip leave" data-drill="month_leave" data-uid="' + r.user_id + '">' + r.leave_days + ' leave</span>'
        : '');

    // late cell (with formula + free-form input when a cut applies)
    let lateCell;
    const lm = r.late_minutes || 0;
    const lateTxt = lm > 0 ? (Math.floor(lm / 60) + 'h ' + (lm % 60) + 'm') : '0m';
    if (r.late_flag) {
      const flagTitle = r.late_flag === 'over_step'
        ? 'Late over ' + Math.round(r.late_step_min / 60) + 'h this month → salary cut for all late hours (no leave used).'
        : 'No leaves left to absorb lateness → salary cut for all late hours.';
      lateCell = '<span class="pr-chip late">' + lateTxt + '</span><span class="pr-flag" title="' + flagTitle + '">⚠</span>' +
        '<div class="pr-formula">' + fmt(r.per_hour) + '/hr × ' + (lm / 60).toFixed(1) + 'h</div>' +
        '<input type="number" class="pr-ded-input" data-lateded="' + i + '" value="' + Math.round(lateDed(r)) + '" min="0">';
    } else if (r.late_leave_deduct > 0) {
      lateCell = '<span class="pr-chip late">' + lateTxt + '</span>' +
        '<div class="pr-formula">−' + r.late_leave_deduct + ' leave (no pay cut)</div>';
    } else {
      lateCell = '<span class="pr-chip muted">' + lateTxt + '</span>' + (lm > 0 ? '<div class="pr-formula">within free buffer</div>' : '');
    }

    // overtime
    const ot = r.bonus_leaves > 0
      ? '<span class="pr-chip ot" data-drill="month_overtime" data-uid="' + r.user_id + '">+' + r.bonus_leaves + ' bonus leave' + (r.bonus_leaves > 1 ? 's' : '') + '</span>'
      : '<span class="pr-chip muted">—</span>';

    // advances (+ always offer "give advance")
    const adv = (r.advance_total > 0
        ? '<span class="pr-adv" data-adv="' + i + '">' + fmt(r.advance_total) + '</span>'
        : '<span class="pr-adv zero">—</span>') +
      '<div><span class="pr-give" data-give="' + i + '">＋ advance</span></div>';

    const totalDed = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0);
    const n = net(r);

    const selectable = r.configured && !r.paid;
    const statusCell = r.paid
      ? '<span class="pr-chip paid" data-paydetail="' + i + '" style="cursor:pointer;" title="View payment details">✓ Paid ' + fmt(r.paid_net != null ? r.paid_net : n) + '</span>' +
        '<div class="pr-formula"><span data-paydetail="' + i + '" style="cursor:pointer;text-decoration:underline dotted;">details</span>' + (r.paid_at ? ' · ' + String(r.paid_at).slice(0, 10) : '') + '</div>'
      : '<span class="pr-chip unpaid">Unpaid</span>';

    return '<tr data-row="' + i + '"' + (r.paid ? ' style="opacity:.72;"' : '') + '>' +
      '<td>' + (selectable ? '<input type="checkbox" class="pr-rowchk" data-chk="' + i + '">' : '') + '</td>' +
      '<td><div class="pr-emp-name">' + esc(r.fullname) + '</div>' +
        (r.designation || r.employee_code ? '<div class="pr-emp-sub">' + esc([r.designation, r.employee_code].filter(Boolean).join(' · ')) + '</div>' : '') +
        (r.staff_expense_count > 0 ? '<div class="pr-dblpay" title="Already reimbursed via a Staff Salaries expense this month. Paying here would pay them twice.">⚠ ' + fmt(r.staff_expense_total) + ' via expense</div>' : '') + '</td>' +
      '<td class="num">' + baseCell + '</td>' +
      '<td>' + att + '</td>' +
      '<td class="pr-late-cell">' + lateCell + '</td>' +
      '<td>' + ot + '</td>' +
      '<td class="num">' + adv + '</td>' +
      '<td class="num"><span class="pr-ded-link" data-ded="' + i + '" data-dedrow="' + i + '">' + fmt(totalDed) + '</span></td>' +
      '<td class="num"><span class="pr-net' + (n < 0 ? ' neg' : '') + '" data-net="' + i + '">' + fmt(Math.max(0, n)) + '</span>' +
        (n < 0 ? '<div class="pr-formula" style="color:#b91c1c;">deductions exceed salary</div>' : '') + '</td>' +
      '<td>' + statusCell + '</td>' +
      '</tr>';
  }

  function wireRow(r, i) {
    // base edit
    const baseSpan = document.querySelector('[data-base="' + i + '"]');
    if (baseSpan) baseSpan.onclick = () => editBase(r, i);
    // checkbox
    const chk = document.querySelector('[data-chk="' + i + '"]');
    if (chk) chk.onchange = () => { r._selected = chk.checked; updatePaybar(); syncSelAll(); };
    // late override
    const ld = document.querySelector('[data-lateded="' + i + '"]');
    if (ld) ld.oninput = () => {
      r._lateOverride = ld.value;
      ld.classList.toggle('override', Number(ld.value) !== Math.round(Number(r.late_deduction || 0)));
      refreshMoney(r, i);
    };
    // advances drill
    const adv = document.querySelector('[data-adv="' + i + '"]');
    if (adv) adv.onclick = () => showAdvances(r);
    // give advance
    const give = document.querySelector('[data-give="' + i + '"]');
    if (give) give.onclick = () => openAdvance(r);
    // deductions breakdown
    const ded = document.querySelector('[data-dedrow="' + i + '"]');
    if (ded) ded.onclick = () => showDeductions(r);
    // paid detail (the frozen receipt)
    document.querySelectorAll('[data-paydetail="' + i + '"]').forEach(pd => {
      pd.onclick = () => showPaidDetail(r);
    });
  }

  function refreshMoney(r, i) {
    const totalDed = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0);
    const n = net(r);
    const dedCell = document.querySelector('[data-ded="' + i + '"]');
    const netCell = document.querySelector('[data-net="' + i + '"]');
    if (dedCell) dedCell.textContent = fmt(totalDed);
    if (netCell) { netCell.textContent = fmt(Math.max(0, n)); netCell.classList.toggle('neg', n < 0); }
    updatePaybar();
  }

  // ---- inline base edit ----
  function editBase(r, i) {
    const td = document.querySelector('[data-base="' + i + '"]').closest('td');
    const cur = r.configured ? Math.round(r.base_salary) : '';
    td.innerHTML = '<input type="number" class="pr-base-input" value="' + cur + '" placeholder="0" min="0">';
    const inp = td.querySelector('input');
    inp.focus(); inp.select();
    const commit = async () => {
      const val = inp.value.trim();
      if (val === '' || Number(val) < 0) { renderRows(); return; }
      inp.disabled = true;
      try {
        const res = await fetch('/hr/payroll/set-salary', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ user_id: r.user_id, base_salary: Number(val) })
        });
        const j = await res.json();
        if (!j.success) throw new Error(j.message || 'Failed');
        await load(); // recompute the month with the new base
      } catch (e) {
        alert('Could not save salary: ' + (e.message || e));
        renderRows();
      }
    };
    inp.onkeydown = (ev) => { if (ev.key === 'Enter') commit(); if (ev.key === 'Escape') renderRows(); };
    inp.onblur = commit;
  }

  // ---- select all ----
  const isPayable = (r) => r.configured && !r.paid;
  el('prSelAll').onchange = () => {
    const on = el('prSelAll').checked;
    ROWS.forEach((r, i) => { if (isPayable(r)) { r._selected = on; const c = document.querySelector('[data-chk="' + i + '"]'); if (c) c.checked = on; } });
    updatePaybar();
  };
  function syncSelAll() {
    const payable = ROWS.filter(isPayable);
    el('prSelAll').checked = payable.length > 0 && payable.every(r => r._selected);
  }

  function selectedRows() { return ROWS.filter(r => r._selected && isPayable(r)); }
  function updatePaybar() {
    const sel = selectedRows();
    const tot = sel.reduce((s, r) => s + Math.max(0, net(r)), 0);
    el('prPaybarSel').textContent = sel.length + ' selected';
    el('prPaybarTot').textContent = fmt(tot);
    el('prPaybar').classList.toggle('show', sel.length > 0);
  }

  // ---- drilldown (absent/leave/overtime dates) ----
  document.addEventListener('click', async (ev) => {
    const t = ev.target.closest('[data-drill]');
    if (!t) return;
    const type = t.getAttribute('data-drill');
    const uid = t.getAttribute('data-uid');
    openSheet(t.textContent.trim(), 'Loading…');
    try {
      const res = await fetch('/attendance/date-breakdown?user_id=' + uid + '&type=' + type + '&month=' + CURMONTH, { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      if (!j.dates.length) { el('prSheetBody').innerHTML = '<div class="pr-empty">Nothing to show.</div>'; return; }
      el('prSheetBody').innerHTML = j.dates.map(d => {
        const dt = new Date(d.date + 'T00:00:00');
        const lbl = dt.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
        return '<div class="pr-daterow"><span class="dt">' + lbl + '</span>' + (d.label ? '<span class="lb">' + esc(d.label) + '</span>' : '') + '</div>';
      }).join('');
    } catch (e) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">Could not load: ' + (e.message || e) + '</div>';
    }
  });

  function showAdvances(r) {
    openSheet('Advances', '');
    if (!r.advances || !r.advances.length) { el('prSheetBody').innerHTML = '<div class="pr-empty">No open advances.</div>'; return; }
    el('prSheetBody').innerHTML = r.advances.map(a =>
      '<div class="pr-daterow"><span class="dt">' + fmt(a.amount) + '</span><span class="lb">' + (a.date || '') + (a.request_number ? ' · ' + esc(a.request_number) : '') + '</span></div>'
    ).join('') + '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Open advances are deducted from this pay and marked settled when you pay.</div>';
  }

  // ---- deductions breakdown ----
  function showDeductions(r) {
    const absent = Number(r.absent_deduction || 0);
    const late = lateDed(r);
    const adv = Number(r.advance_total || 0);
    const total = absent + late + adv;
    const dedLine = (label, amount, formula, muted) =>
      '<div class="pr-daterow"><div><div class="dt"' + (muted ? ' style="color:#9ca3af;"' : '') + '>' + label + '</div>' +
      (formula ? '<div class="lb">' + esc(formula) + '</div>' : '') + '</div>' +
      '<span class="dt"' + (muted ? ' style="color:#cbd5e1;"' : '') + '>' + (amount > 0 ? '− ' + fmt(amount) : '—') + '</span></div>';

    let html = '';
    html += dedLine('Absent days', absent,
      r.absent_days > 0 ? (r.absent_days + ' × ' + fmt(r.per_day) + '/day') : 'no unapproved absences', absent === 0);
    html += dedLine('Late', late,
      late > 0 ? ((r.late_minutes / 60).toFixed(1) + 'h × ' + fmt(r.per_hour) + '/hr')
        : (r.late_leave_deduct > 0 ? 'covered by 1 leave (no pay cut)' : 'within free buffer'), late === 0);
    html += dedLine('Advances', adv,
      adv > 0 ? 'open advance — settled when you pay' : 'none open', adv === 0);
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;margin-top:4px;"><span class="dt">Total deductions</span><span class="dt">' + fmt(total) + '</span></div>';
    html += '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Net pay = base ' + fmt(r.base_salary) + ' − deductions ' + fmt(total) + ' = <b>' + fmt(Math.max(0, net(r))) + '</b></div>';

    el('prSheetTitle').textContent = 'Deductions — ' + r.fullname;
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
  }

  // ---- paid detail (the frozen receipt of what was actually paid) ----
  function showPaidDetail(r) {
    const d = r.paid_detail;
    if (!d) { openSheet('Payment — ' + r.fullname, 'No payment record found.'); return; }
    const line = (label, value, neg) =>
      '<div class="pr-daterow"><span class="dt" style="font-weight:400;color:#6b7280;">' + label + '</span>' +
      '<span class="dt"' + (neg ? ' style="color:#b91c1c;"' : '') + '>' + value + '</span></div>';

    let html = '';
    html += line('Paid on', d.paid_at ? String(d.paid_at).slice(0, 16) : '—');
    if (d.paid_by_name) html += line('Paid by', esc(d.paid_by_name));
    html += line('Paid from', d.funding === 'online' ? ('🏦 ' + esc(d.bank_label || 'Online')) : '💵 NF Cash');
    html += '<div style="height:8px;"></div>';
    html += line('Base salary', fmt(d.base));
    html += line('Attendance', d.present_days + ' present · ' + d.absent_days + ' absent' + (d.leave_days > 0 ? ' · ' + d.leave_days + ' leave' : ''));
    if (d.absent_deduction > 0) html += line('Absent deduction', '− ' + fmt(d.absent_deduction), true);
    if (d.late_minutes > 0) {
      const lt = Math.floor(d.late_minutes / 60) + 'h ' + (d.late_minutes % 60) + 'm';
      if (d.late_deduction > 0) html += line('Late (' + lt + ')', '− ' + fmt(d.late_deduction), true);
      else if (d.late_leave_deduct > 0) html += line('Late (' + lt + ')', '−' + d.late_leave_deduct + ' leave');
      else html += line('Late (' + lt + ')', 'free buffer');
    }
    if (d.advance_total > 0) html += line('Advances settled', '− ' + fmt(d.advance_total), true);
    if (d.bonus_leaves > 0) html += line('Overtime bonus', '+' + d.bonus_leaves + ' leave' + (d.bonus_leaves > 1 ? 's' : ''));
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;margin-top:4px;"><span class="dt">Net paid</span><span class="dt" style="color:#047857;">' + fmt(d.net) + '</span></div>';
    if (d.ledger_id) html += '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Ledger entry #' + d.ledger_id + '</div>';

    el('prSheetTitle').textContent = 'Payment — ' + r.fullname;
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
  }

  function openSheet(title, body) {
    el('prSheetTitle').textContent = title;
    el('prSheetBody').innerHTML = body ? '<div class="pr-empty">' + body + '</div>' : '';
    el('prSheet').classList.add('show');
  }
  el('prSheetX').onclick = () => el('prSheet').classList.remove('show');
  el('prSheet').onclick = (ev) => { if (ev.target === el('prSheet')) el('prSheet').classList.remove('show'); };

  // ---- pay modal ----
  function buildFundModal() {
    const cashLbl = FUND.cash ? ('NF Cash (' + FUND.cash.name + ')') : 'NF Cash';
    const bankOpts = '<option value="">Select bank…</option>' +
      (FUND.banks || []).map(b => '<option value="' + b.id + '">' + esc(b.label) + '</option>').join('');
    el('prCashLabel').textContent = cashLbl;
    el('prBankSel').innerHTML = bankOpts;
    el('prAdvCashLabel').textContent = cashLbl;
    el('prAdvBankSel').innerHTML = bankOpts;
  }

  // ---- give advance ----
  let ADV_ROW = null;
  function openAdvance(r) {
    ADV_ROW = r;
    el('prAdvWho').innerHTML = 'To <b>' + esc(r.fullname) + '</b>' + (r.advance_total > 0 ? ' · open advances ' + fmt(r.advance_total) : '');
    el('prAdvAmount').value = '';
    el('prAdvNote').value = '';
    // reset funding to cash
    document.querySelectorAll('[data-advfund]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-advfund="cash"]').classList.add('active');
    document.querySelector('input[name=prAdvFund][value=cash]').checked = true;
    el('prAdvBankSel').disabled = true;
    el('prAdvModal').classList.add('show');
    setTimeout(() => el('prAdvAmount').focus(), 50);
  }
  document.querySelectorAll('[data-advfund]').forEach(f => {
    f.onclick = (ev) => {
      if (ev.target.closest('select')) return; // same select-click guard as the pay modal
      document.querySelectorAll('[data-advfund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-advfund') !== 'online');
      if (el('prAdvBankSel').disabled !== dis) el('prAdvBankSel').disabled = dis;
    };
  });
  el('prAdvCancel').onclick = () => el('prAdvModal').classList.remove('show');
  el('prAdvModal').onclick = (ev) => { if (ev.target === el('prAdvModal')) el('prAdvModal').classList.remove('show'); };
  el('prAdvConfirm').onclick = async () => {
    if (!ADV_ROW) return;
    const amount = Number(el('prAdvAmount').value);
    if (!amount || amount < 1) { alert('Enter an amount.'); return; }
    const fundType = document.querySelector('input[name=prAdvFund]:checked').value;
    const bankId = el('prAdvBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Choose the bank.'); return; }
    el('prAdvConfirm').disabled = true; el('prAdvConfirm').textContent = 'Giving…';
    try {
      const res = await fetch('/hr/payroll/give-advance', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: ADV_ROW.user_id, amount, funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null, note: el('prAdvNote').value || null })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prAdvModal').classList.remove('show');
      await load();
    } catch (e) {
      alert('Could not give advance: ' + (e.message || e));
    } finally {
      el('prAdvConfirm').disabled = false; el('prAdvConfirm').textContent = 'Give advance';
    }
  };
  document.querySelectorAll('[data-fund]').forEach(f => {
    f.onclick = (ev) => {
      // Clicks on the bank <select> must not re-run the fund toggle — re-setting
      // `disabled` mid-click cancels the native dropdown from ever opening.
      if (ev.target.closest('select')) return;
      document.querySelectorAll('[data-fund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-fund') !== 'online');
      if (el('prBankSel').disabled !== dis) el('prBankSel').disabled = dis;
    };
  });
  el('prPayBtn').onclick = () => {
    const sel = selectedRows();
    if (!sel.length) return;
    el('prPayList').innerHTML = sel.map(r =>
      '<div class="r"><span>' + esc(r.fullname) + '</span><span>' + fmt(Math.max(0, net(r))) + '</span></div>'
    ).join('') + '<div class="r" style="font-weight:700;background:#fafbfc;"><span>Total</span><span>' +
      fmt(sel.reduce((s, r) => s + Math.max(0, net(r)), 0)) + '</span></div>';
    el('prPayModal').classList.add('show');
  };
  el('prPayCancel').onclick = () => el('prPayModal').classList.remove('show');
  el('prPayModal').onclick = (ev) => { if (ev.target === el('prPayModal')) el('prPayModal').classList.remove('show'); };

  el('prPayConfirm').onclick = async () => {
    const sel = selectedRows();
    if (!sel.length) return;
    const fundType = document.querySelector('input[name=prFund]:checked').value;
    const bankId = el('prBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Please choose the bank you are paying from.'); return; }
    const payload = {
      month: CURMONTH,
      funding: fundType,
      bank_id: fundType === 'online' ? Number(bankId) : null,
      items: sel.map(r => ({ user_id: r.user_id, net: Math.max(0, net(r)), late_deduction: lateDed(r) }))
    };
    el('prPayConfirm').disabled = true; el('prPayConfirm').textContent = 'Paying…';
    try {
      const res = await fetch('/hr/payroll/pay', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(payload)
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prPayModal').classList.remove('show');
      alert(j.message || 'Salaries paid.');
      await load();
    } catch (e) {
      alert('Payment failed: ' + (e.message || e));
    } finally {
      el('prPayConfirm').disabled = false; el('prPayConfirm').textContent = 'Confirm payment';
    }
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  // auto-load current month
  load();
})();
</script>
@endsection
