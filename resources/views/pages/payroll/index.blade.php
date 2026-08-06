@extends('layouts.app')

@section('title', 'Payroll')

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
  .pr-chip.late    { background: #fff7ed; color: #c2410c; cursor: pointer; }
  .pr-chip.ot      { background: #f5f3ff; color: #6d28d9; cursor: pointer; }
  .pr-chip.muted   { background: #f3f4f6; color: #9ca3af; }
  .pr-chip.paid    { background: #ecfdf5; color: #047857; }
  .pr-chip.unpaid  { background: #f3f4f6; color: #6b7280; }
  .pr-flag { display: inline-block; margin-left: 4px; color: #b45309; cursor: help; }

  .pr-late-cell { min-width: 130px; }
  .pr-formula { font-size: 10.5px; color: #9ca3af; margin-top: 3px; }
  .pr-ded-input { width: 88px; height: 28px; border: 1px solid #d1d5db; border-radius: 6px; padding: 0 7px; font-size: 12.5px; text-align: right; margin-top: 4px; }
  .pr-ded-input.override { border-color: #c2410c; background: #fff7ed; }
  .pr-chip.dim { opacity: .4; }
  /* Manager bypass toggle for overtime bonus / late-leave deduction (applied on Pay). */
  .pr-lvtog { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 10.5px; font-weight: 700; cursor: pointer; user-select: none; border-radius: 6px; padding: 2px 7px; line-height: 1.4; }
  .pr-lvtog.on  { color: #047857; background: #ecfdf5; }
  .pr-lvtog.off { color: #9ca3af; background: #f3f4f6; }

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

  /* tabs */
  .pr-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 14px; }
  .pr-tab { padding: 9px 16px; font-size: 13.5px; font-weight: 600; color: #6b7280; cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
  .pr-tab:hover { color: #374151; }
  .pr-tab.active { color: #16a34a; border-bottom-color: #16a34a; }
  .pr-view { display: none; }
  .pr-view.active { display: block; }

  /* business-unit chip + settings gear on rows */
  .pr-emp-tags { display: flex; align-items: center; gap: 6px; margin-top: 3px; }
  .pr-bu { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; letter-spacing: .02em; }
  .pr-bu.bu-NF { background: #eef2ff; color: #4338ca; }
  .pr-bu.bu-KHAAS { background: #ecfeff; color: #0e7490; }
  .pr-gear { cursor: pointer; color: #9ca3af; font-size: 13px; line-height: 1; }
  .pr-gear:hover { color: #374151; }
  .pr-ratepill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; background: #f3f4f6; color: #6b7280; }

  /* custom tab: employee cards */
  .pr-cust-list { display: flex; flex-direction: column; gap: 12px; }
  .pr-cust-card { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; padding: 14px 16px; }
  .pr-cust-top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; justify-content: space-between; }
  .pr-cust-name { font-weight: 700; color: #111827; font-size: 14px; }
  .pr-cust-meta { display: flex; align-items: center; gap: 8px; margin-top: 3px; }
  .pr-rate { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; padding: 2px 7px; border-radius: 6px; }
  .pr-rate:hover { background: #f3f4f6; }
  .pr-rate .amt { font-weight: 700; color: #111827; }
  .pr-rate .unit { font-size: 11px; color: #9ca3af; }
  .pr-cover { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; align-items: center; }
  .pr-cover-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
  .pr-cover-chip.paid { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
  .pr-cover-chip.none { background: #f9fafb; color: #9ca3af; border: 1px dashed #e5e7eb; cursor: default; }
  .pr-cover-add { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 700; background: #16a34a; color: #fff; border: none; cursor: pointer; }
  .pr-cover-add:hover { background: #15803d; }
  .pr-cust-adv { margin-top: 8px; font-size: 11.5px; color: #b45309; }
  .pr-cust-adv .give { color: #2563eb; cursor: pointer; margin-left: 8px; }
  .pr-cust-adv .give:hover { text-decoration: underline; }
  .pr-cust-adv .view { font-weight: 700; cursor: pointer; text-decoration: underline dotted; }

  /* custom pay modal computed line */
  .pr-calc { background: #f9fafb; border: 1px solid #eef0f2; border-radius: 10px; padding: 12px 14px; margin: 4px 0 12px; }
  .pr-calc .row { display: flex; justify-content: space-between; font-size: 12.5px; padding: 3px 0; color: #374151; }
  .pr-calc .row.tot { font-weight: 700; color: #111827; border-top: 1px solid #eef0f2; margin-top: 4px; padding-top: 7px; }
  .pr-calc .ref { font-size: 11px; color: #9ca3af; margin-top: 6px; }
  .pr-field { margin-bottom: 12px; }
  .pr-field label { display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px; }
  .pr-input { width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 10px; font-size: 13px; }
  .pr-input:focus { border-color: #16a34a; outline: none; }
  .pr-radio-row { display: flex; gap: 8px; }
  .pr-radio { flex: 1; border: 1px solid #e5e7eb; border-radius: 9px; padding: 9px 12px; cursor: pointer; text-align: center; font-size: 12.5px; font-weight: 600; color: #6b7280; }
  .pr-radio.active { border-color: #16a34a; background: #f0fdf4; color: #047857; }
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

  <div class="pr-tabs">
    <button class="pr-tab active" id="prTabMonthly" data-tab="monthly">Monthly</button>
    <button class="pr-tab" id="prTabCustom" data-tab="custom">Custom schedule</button>
  </div>

  {{-- Monthly view (the original grid) --}}
  <div class="pr-view active" id="prMonthlyView">
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

  {{-- Custom-schedule view (date-range / weekly employees) --}}
  <div class="pr-view" id="prCustomView">
    <div class="pr-sub" style="margin-bottom:12px;">
      Employees paid by date range. Enter each period; overlapping days are blocked. A period that
      ends next month is filed under that month.
    </div>
    <div class="pr-cust-list" id="prCustList">
      <div class="pr-empty">Loading…</div>
    </div>
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
    <div class="pr-modal-h" id="prAdvModalTitle">Give advance</div>
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

{{-- employee settings modal (pay schedule + business unit) --}}
<div class="pr-modal-back" id="prSetModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prSetTitle">Pay settings</div>
    <div class="pr-modal-b">
      <div class="pr-field">
        <label>Pay schedule</label>
        <div class="pr-radio-row" id="prSetSched">
          <div class="pr-radio active" data-sched="monthly">Monthly</div>
          <div class="pr-radio" data-sched="custom">Custom (by date range)</div>
        </div>
      </div>
      <div class="pr-field" id="prSetRateWrap" style="display:none;">
        <label>Rate is per</label>
        <div class="pr-radio-row" id="prSetRate">
          <div class="pr-radio active" data-rate="monthly">Month (base salary)</div>
          <div class="pr-radio" data-rate="daily">Day</div>
        </div>
      </div>
      <div class="pr-field" id="prSetBuWrap" style="display:none;">
        <label>Business unit (salary expense)</label>
        <div class="pr-radio-row" id="prSetBu">
          <div class="pr-radio active" data-bu="NF">Nizami Farms</div>
          <div class="pr-radio" data-bu="KHAAS">Khaas / Frozen</div>
        </div>
      </div>
      <div style="font-size:11.5px;color:#9ca3af;">Changes apply to future payments; already-paid records are unchanged.
        The base salary number keeps its value — if you switch between per-day and per-month, update the rate after saving.</div>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prSetCancel">Cancel</button>
      <button class="pr-btn-primary" id="prSetSave">Save</button>
    </div>
  </div>
</div>

{{-- custom period pay modal --}}
<div class="pr-modal-back" id="prCustModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prCustTitle">Add period</div>
    <div class="pr-modal-b">
      <div style="display:flex;gap:10px;">
        <div class="pr-field" style="flex:1;">
          <label>From</label>
          <input type="date" class="pr-input" id="prCustStart">
        </div>
        <div class="pr-field" style="flex:1;">
          <label>To</label>
          <input type="date" class="pr-input" id="prCustEnd">
        </div>
      </div>
      <div class="pr-calc" id="prCustCalc">
        <div class="pr-calc-inner" id="prCustCalcInner"><div class="row"><span>Pick the dates above</span><span></span></div></div>
      </div>
      <div class="pr-field">
        <label>Amount to pay (edit if needed)</label>
        <input type="number" class="pr-input" id="prCustAmount" min="0" placeholder="0">
      </div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Pay from</div>
      <label class="pr-fund active" data-custfund="cash">
        <input type="radio" name="prCustFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prCustCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-custfund="online">
        <input type="radio" name="prCustFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prCustBankSel" disabled></select>
      </label>
      <input type="text" id="prCustNote" class="pr-input" style="margin-top:10px;" placeholder="Note (optional)">
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prCustCancel">Cancel</button>
      <button class="pr-btn-primary" id="prCustConfirm">Pay period</button>
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
  let ROWS = [];        // computed rows from server (monthly)
  let FUND = { cash: null, banks: [] };
  let CURMONTH = '';
  let SCHEDULE_AVAILABLE = false; // schema applied → schedule gear + Custom tab usable
  let KHAAS_AVAILABLE = false;    // manager may tag/see Khaas
  let KHAAS_BU_ID = null;         // the Khaas business-unit id (to post)
  let CAN_VOID = false;           // owner-only: may void a wrongly-given advance
  let ADV_SHEET = { row: null, mode: null }; // the advance list currently open in the sheet
  let TAB = 'monthly';
  let CUST_ROWS = [];             // custom-schedule rows
  let CUST_LOADED_MONTH = '';     // month the custom list was last loaded for

  // ---- month init ----
  const now = new Date();
  const dflt = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
  el('prMonth').value = dflt;

  function shiftMonth(delta) {
    const [y, m] = el('prMonth').value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    el('prMonth').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }
  el('prPrev').onclick = () => { shiftMonth(-1); reloadActive(); };
  el('prNext').onclick = () => { shiftMonth(1); reloadActive(); };
  el('prGen').onclick = reloadActive;
  el('prMonth').onchange = reloadActive;

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
      SCHEDULE_AVAILABLE = !!j.schedule_available;
      KHAAS_AVAILABLE = !!j.khaas_available;
      KHAAS_BU_ID = j.khaas_bu_id || null;
      CAN_VOID = !!j.can_void_advance;
      ROWS = (j.rows || []).map(r => ({ ...r, _selected: false, _lateOverride: null, _netOverride: null, _skipOvertime: false, _skipLateLeave: false }));
      renderStrip();
      renderRows();
      buildFundModal();
      el('prTabCustom').style.display = SCHEDULE_AVAILABLE ? '' : 'none';
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
  // Whether the manager typed a manual take-home amount, and the effective net to pay.
  function hasNetOverride(r) { return r._netOverride !== null && r._netOverride !== undefined && r._netOverride !== ''; }
  function effNet(r) { return hasNetOverride(r) ? Math.max(0, Number(r._netOverride)) : Math.max(0, net(r)); }

  function renderStrip() {
    const configured = ROWS.filter(r => r.configured);
    const totalNet = ROWS.reduce((s, r) => s + effNet(r), 0);
    const missing = ROWS.filter(r => !r.configured).length;
    // Requests waiting on a decision. Derived from the rows themselves so the card and the
    // per-row chips can never disagree; the sheet re-fetches the live list when opened.
    const reqCount = ROWS.reduce((s, r) => s + ((r.pending_requests || []).length), 0);
    const reqTotal = ROWS.reduce((s, r) => s + Number(r.pending_request_total || 0), 0);
    el('prStrip').innerHTML =
      card('Employees', ROWS.length) +
      card('With salary set', configured.length) +
      card('Total net (month)', fmt(totalNet)) +
      (missing > 0 ? card('Need salary', missing) : '') +
      (reqCount > 0 ? requestCard(reqCount, reqTotal) : '');
    const rc = document.getElementById('prReqCard');
    if (rc) rc.onclick = () => openRequestSheet();
  }
  function card(k, v) { return '<div class="pr-card"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }

  // Amber, clickable — money NOT yet given, waiting on the manager.
  function requestCard(n, total) {
    return '<div class="pr-card" id="prReqCard" style="cursor:pointer;border-color:#fcd34d;background:#fffbeb;">' +
      '<div class="k" style="color:#92400e;">Advance requests</div>' +
      '<div class="v" style="color:#b45309;">' + n + ' <span style="font-size:12px;font-weight:600;">· ' + fmt(total) + '</span></div>' +
      '<div style="font-size:10.5px;color:#a16207;margin-top:2px;">not given yet · click to review</div></div>';
  }

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
        : '') +
      '<div class="pr-formula"><span data-drill="leave_grants" data-uid="' + r.user_id + '" style="cursor:pointer;text-decoration:underline dotted;">leave history ›</span></div>';

    // late cell (with formula + free-form input when a cut applies)
    let lateCell;
    const lm = r.late_minutes || 0;
    const lateTxt = lm > 0 ? (Math.floor(lm / 60) + 'h ' + (lm % 60) + 'm') : '0m';
    if (r.late_flag) {
      const flagTitle = r.late_flag === 'over_step'
        ? 'Late over ' + Math.round(r.late_step_min / 60) + 'h this month → salary cut for all late hours (no leave used).'
        : 'No leaves left to absorb lateness → salary cut for all late hours.';
      lateCell = '<span class="pr-chip late" data-drill="month_late" data-uid="' + r.user_id + '" title="Click to see the late days">' + lateTxt + '</span><span class="pr-flag" title="' + flagTitle + '">⚠</span>' +
        '<div class="pr-formula">' + fmt(r.per_hour) + '/hr × ' + (lm / 60).toFixed(1) + 'h</div>' +
        '<input type="number" class="pr-ded-input" data-lateded="' + i + '" value="' + Math.round(lateDed(r)) + '" min="0">';
    } else if (r.late_leave_deduct > 0) {
      // Recommendation: remove 1 leave for lateness. Manager can bypass (keep the leave); applied on Pay.
      lateCell = '<span class="pr-chip late' + (r._skipLateLeave ? ' dim' : '') + '" data-drill="month_late" data-uid="' + r.user_id + '" title="Click to see the late days">' + lateTxt + '</span>' +
        '<div class="pr-formula">−' + r.late_leave_deduct + ' leave (no pay cut)</div>' +
        '<div class="pr-lvtog ' + (r._skipLateLeave ? 'off' : 'on') + '" data-skiplate="' + i + '" title="Deduct 1 leave for lateness? Click to toggle. Applied when you Pay.">' + (r._skipLateLeave ? '✕ kept (not deducted)' : '✓ will deduct') + '</div>';
    } else {
      lateCell = '<span class="pr-chip muted">' + lateTxt + '</span>' + (lm > 0 ? '<div class="pr-formula">within free buffer</div>' : '');
    }

    // overtime → bonus leave (manager can bypass; applied on Pay)
    const ot = r.bonus_leaves > 0
      ? '<span class="pr-chip ot' + (r._skipOvertime ? ' dim' : '') + '" data-drill="month_overtime" data-uid="' + r.user_id + '" title="Click to see the overtime days">+' + r.bonus_leaves + ' bonus leave' + (r.bonus_leaves > 1 ? 's' : '') + '</span>' +
        '<div class="pr-lvtog ' + (r._skipOvertime ? 'off' : 'on') + '" data-skipot="' + i + '" title="Give this overtime bonus to the employee? Click to toggle. Applied when you Pay.">' + (r._skipOvertime ? '✕ bypassed' : '✓ will add') + '</div>'
      : '<span class="pr-chip muted">—</span>';

    // advances (+ always offer "give advance")
    // The amber "requested" chip is money NOT given: it is deliberately rendered BELOW the real
    // total, in a different colour, with its own wording — it never joins advance_total, the
    // deductions breakdown or net pay.
    const pendReq = (r.pending_requests || []).length;
    const reqChip = pendReq > 0
      ? '<div><span data-reqrow="' + i + '" title="Employee asked for an advance — not given yet. Click to decide."' +
        ' style="display:inline-block;margin-top:3px;font-size:10.5px;font-weight:700;color:#b45309;background:#fef3c7;' +
        'border:1px solid #fcd34d;border-radius:5px;padding:1px 6px;cursor:pointer;white-space:nowrap;">⏳ ' +
        pendReq + ' requested · ' + fmt(r.pending_request_total) + '</span>' +
        '<div style="font-size:9.5px;color:#a16207;line-height:1.2;">not given yet</div></div>'
      : '';
    const adv = (r.advance_total > 0
        ? '<span class="pr-adv" data-adv="' + i + '">' + fmt(r.advance_total) + '</span>'
        : '<span class="pr-adv zero">—</span>') +
      reqChip +
      '<div><span class="pr-give" data-give="' + i + '">＋ advance</span></div>';

    const totalDed = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0);
    const n = net(r);
    const ov = hasNetOverride(r);
    const shownNet = effNet(r);
    // Net pay cell: click to type a manual take-home amount (bypasses attendance deductions).
    const netCell = '<span class="pr-net' + (!ov && n < 0 ? ' neg' : '') + '" data-net="' + i + '" data-netedit="' + i + '" style="cursor:pointer;" title="Click to set a manual amount to pay">' + fmt(shownNet) + ' <span class="pen" style="font-size:11px;color:#9ca3af;">✎</span></span>' +
      (ov
        ? '<div class="pr-formula" style="color:#7c3aed;">manual · <span data-netclear="' + i + '" style="cursor:pointer;text-decoration:underline dotted;">use calc ' + fmt(Math.max(0, n)) + '</span></div>'
        : (n < 0 ? '<div class="pr-formula" style="color:#b91c1c;">deductions exceed salary</div>' : ''));

    const selectable = r.configured && !r.paid;
    const statusCell = r.paid
      ? '<span class="pr-chip paid" data-paydetail="' + i + '" style="cursor:pointer;" title="View payment details">✓ Paid ' + fmt(r.paid_net != null ? r.paid_net : n) + '</span>' +
        '<div class="pr-formula"><span data-paydetail="' + i + '" style="cursor:pointer;text-decoration:underline dotted;">details</span>' + (r.paid_at ? ' · ' + String(r.paid_at).slice(0, 10) : '') + '</div>' +
        (paidFromLabel(r.paid_detail) ? '<div class="pr-formula">' + paidFromLabel(r.paid_detail) + '</div>' : '')
      : '<span class="pr-chip unpaid">Unpaid</span>';

    return '<tr data-row="' + i + '"' + (r.paid ? ' style="opacity:.72;"' : '') + '>' +
      '<td>' + (selectable ? '<input type="checkbox" class="pr-rowchk" data-chk="' + i + '"' + (r._selected ? ' checked' : '') + '>' : '') + '</td>' +
      '<td><div class="pr-emp-name">' + esc(r.fullname) + '</div>' +
        (r.designation || r.employee_code ? '<div class="pr-emp-sub">' + esc([r.designation, r.employee_code].filter(Boolean).join(' · ')) + '</div>' : '') +
        empTags(r, i) +
        (r.staff_expense_count > 0 ? '<div class="pr-dblpay" data-dblpay="' + i + '" style="cursor:pointer;" title="Click to see the Staff Salaries expense records. Paying here too would pay them twice.">⚠ ' + fmt(r.staff_expense_total) + ' via expense · view ›</div>' : '') + '</td>' +
      '<td class="num">' + baseCell + '</td>' +
      '<td>' + att + '</td>' +
      '<td class="pr-late-cell">' + lateCell + '</td>' +
      '<td>' + ot + '</td>' +
      '<td class="num">' + adv + '</td>' +
      '<td class="num"><span class="pr-ded-link" data-ded="' + i + '" data-dedrow="' + i + '">' + fmt(totalDed) + '</span></td>' +
      '<td class="num">' + netCell + '</td>' +
      '<td>' + statusCell + '</td>' +
      '</tr>';
  }

  // Business-unit chip + settings gear shown under an employee's name.
  function empTags(r, i) {
    if (!SCHEDULE_AVAILABLE) return '';
    let chip = '';
    if (KHAAS_AVAILABLE) {
      const code = r.bu_code === 'KHAAS' ? 'KHAAS' : 'NF';
      chip = '<span class="pr-bu bu-' + code + '">' + (code === 'KHAAS' ? 'Khaas' : 'NF') + '</span>';
    }
    return '<div class="pr-emp-tags">' + chip +
      '<span class="pr-gear" data-settings="' + i + '" title="Pay schedule & business unit">⚙ settings</span></div>';
  }

  function wireRow(r, i) {
    // base edit
    const baseSpan = document.querySelector('[data-base="' + i + '"]');
    if (baseSpan) baseSpan.onclick = () => editBase(r, i);
    // settings gear (schedule + business unit)
    const gear = document.querySelector('[data-settings="' + i + '"]');
    if (gear) gear.onclick = () => openSettings(r);
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
    // pending-request chip → the same review sheet, filtered to this employee
    const rq = document.querySelector('[data-reqrow="' + i + '"]');
    if (rq) rq.onclick = () => openRequestSheet(r.user_id);
    // deductions breakdown
    const ded = document.querySelector('[data-dedrow="' + i + '"]');
    if (ded) ded.onclick = () => showDeductions(r);
    // manual net override: click the net to type an amount, or clear back to computed
    const ne = document.querySelector('[data-netedit="' + i + '"]');
    if (ne) ne.onclick = () => editNet(r, i);
    const nc = document.querySelector('[data-netclear="' + i + '"]');
    if (nc) nc.onclick = (ev) => { ev.stopPropagation(); r._netOverride = null; renderRows(); };
    // double-pay chip → the underlying Staff Salaries expense records
    const dp = document.querySelector('[data-dblpay="' + i + '"]');
    if (dp) dp.onclick = () => showStaffExpense(r);
    // paid detail (the frozen receipt)
    document.querySelectorAll('[data-paydetail="' + i + '"]').forEach(pd => {
      pd.onclick = () => showPaidDetail(r);
    });
    // bypass toggles: overtime bonus + late-leave deduction (in-place, no full re-render)
    const sot = document.querySelector('[data-skipot="' + i + '"]');
    if (sot) sot.onclick = () => {
      r._skipOvertime = !r._skipOvertime;
      sot.classList.toggle('off', r._skipOvertime); sot.classList.toggle('on', !r._skipOvertime);
      sot.textContent = r._skipOvertime ? '✕ bypassed' : '✓ will add';
      const chip = sot.parentElement.querySelector('.pr-chip.ot'); if (chip) chip.classList.toggle('dim', r._skipOvertime);
    };
    const slate = document.querySelector('[data-skiplate="' + i + '"]');
    if (slate) slate.onclick = () => {
      r._skipLateLeave = !r._skipLateLeave;
      slate.classList.toggle('off', r._skipLateLeave); slate.classList.toggle('on', !r._skipLateLeave);
      slate.textContent = r._skipLateLeave ? '✕ kept (not deducted)' : '✓ will deduct';
      const chip = slate.parentElement.querySelector('.pr-chip.late'); if (chip) chip.classList.toggle('dim', r._skipLateLeave);
    };
  }

  function refreshMoney(r, i) {
    const totalDed = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0);
    const dedCell = document.querySelector('[data-ded="' + i + '"]');
    const netCell = document.querySelector('[data-net="' + i + '"]');
    if (dedCell) dedCell.textContent = fmt(totalDed);
    if (netCell) {
      netCell.innerHTML = fmt(effNet(r)) + ' <span class="pen" style="font-size:11px;color:#9ca3af;">✎</span>';
      netCell.classList.toggle('neg', !hasNetOverride(r) && net(r) < 0);
    }
    updatePaybar();
  }

  // Inline edit of the manual take-home amount (empty clears it back to the computed net).
  function editNet(r, i) {
    const span = document.querySelector('[data-netedit="' + i + '"]');
    if (!span) return;
    const td = span.closest('td');
    const cur = hasNetOverride(r) ? Math.round(Number(r._netOverride)) : Math.round(Math.max(0, net(r)));
    td.innerHTML = '<input type="number" class="pr-base-input" value="' + cur + '" min="0" style="width:112px;">';
    const inp = td.querySelector('input'); inp.focus(); inp.select();
    const commit = () => {
      const v = inp.value.trim();
      r._netOverride = (v === '') ? null : String(Math.max(0, Number(v)));
      renderRows();
    };
    inp.onkeydown = (ev) => { if (ev.key === 'Enter') commit(); if (ev.key === 'Escape') renderRows(); };
    inp.onblur = commit;
  }

  // The Staff Salaries expense records behind a row's double-pay warning.
  async function showStaffExpense(r) {
    openSheet('Staff-salary expenses — ' + r.fullname, 'Loading…');
    try {
      const res = await fetch('/hr/payroll/staff-expense-detail?user_id=' + r.user_id + '&month=' + CURMONTH, { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      const recs = j.records || [];
      if (!recs.length) { el('prSheetBody').innerHTML = '<div class="pr-empty">No records found.</div>'; return; }
      const total = recs.reduce((s, x) => s + Number(x.amount || 0), 0);
      el('prSheetBody').innerHTML = recs.map(x =>
        '<div class="pr-daterow"><div><div class="dt">' + fmt(x.amount) + '</div><div class="lb">' + esc(x.request_number || '') + (x.category ? ' · ' + esc(x.category) : '') + '</div></div><span class="lb">' + esc(x.date || '') + '</span></div>'
      ).join('') +
      '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;"><span class="dt">Total</span><span class="dt">' + fmt(total) + '</span></div>' +
      '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Approved “Staff Salaries” expense entries for this person in ' + esc(monthLabel()) + '. Paying salary here as well would pay them twice — only pay if these were for something else.</div>';
    } catch (e) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">Could not load: ' + (e.message || e) + '</div>';
    }
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
    const tot = sel.reduce((s, r) => s + effNet(r), 0);
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

  // mode 'custom' = the recovery rule differs: a custom period only recovers what
  // that period's pay can absorb, so the note must not promise full settlement.
  function showAdvances(r, mode) {
    ADV_SHEET = { row: r, mode: mode };
    openSheet('Advances — ' + r.fullname, '');
    renderAdvanceSheet();
  }

  // "Paid from" in ONE shape everywhere it appears (the grid row's sub-line and both frozen
  // receipts): an online salary names the BANK, because "Online" alone can't be reconciled
  // against a bank statement. Takes a paid_detail / paid_period object.
  function paidFromLabel(d) {
    if (!d || !d.funding) return '';
    return d.funding === 'online' ? '🏦 ' + esc(d.bank_label || 'Online') : '💵 NF Cash';
  }

  // Where the money actually came from. "Online Bank" is one chart account shared by every bank,
  // so for online advances the BANK is the useful fact — that's the balance a void moves back.
  // An online advance with no bank tag is legacy (pre per-bank tracking); say so rather than
  // implying it came from nowhere.
  function fundLabel(a) {
    if (!a.is_online) return a.source ? '💵 ' + esc(a.source) : '';
    return a.bank ? '🏦 ' + esc(a.bank) : '🏦 Online · bank not recorded';
  }

  // Each open advance shows WHERE the money came from, WHO gave it and the note, so two
  // same-amount advances can be told apart before acting. 🗑 Void appears only for the owner
  // (server flag CAN_VOID, re-checked server-side on every call).
  function renderAdvanceSheet() {
    const r = ADV_SHEET.row;
    if (!r) return;
    if (!r.advances || !r.advances.length) { el('prSheetBody').innerHTML = '<div class="pr-empty">No open advances.</div>'; return; }
    const note = ADV_SHEET.mode === 'custom'
      ? 'Open advances are recovered from the next period you pay — oldest first, and only as much as that pay can cover. An advance bigger than the pay stays open for a later period.'
      : 'Open advances are deducted from this pay and marked settled when you pay.';
    el('prSheetBody').innerHTML = r.advances.map((a, ai) => {
      const meta = [a.date || '', a.request_number ? esc(a.request_number) : '', fundLabel(a)]
        .filter(Boolean).join(' · ');
      const by = a.given_by ? '<div class="lb" style="color:#9ca3af;">given by ' + esc(a.given_by) + '</div>' : '';
      const nt = a.note ? '<div class="lb" style="color:#9ca3af;font-style:italic;">“' + esc(a.note) + '”</div>' : '';
      const voidBtn = (CAN_VOID && a.voidable)
        ? '<button type="button" data-voidadv="' + ai + '" title="Void this advance and return the money"' +
          ' style="margin-left:10px;font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:4px 9px;cursor:pointer;white-space:nowrap;">🗑 Void</button>'
        : '';
      return '<div class="pr-daterow" style="align-items:flex-start;">' +
        '<div><div class="dt">' + fmt(a.amount) + '</div><div class="lb">' + meta + '</div>' + by + nt + '</div>' +
        '<div style="display:flex;align-items:center;">' + voidBtn + '</div></div>';
    }).join('')
      + '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;"><span class="dt">Total open</span><span class="dt">' + fmt(r.advance_total) + '</span></div>'
      + '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">' + note + '</div>';

    (r.advances || []).forEach((a, ai) => {
      const b = document.querySelector('[data-voidadv="' + ai + '"]');
      if (b) b.onclick = () => voidAdvance(r, a);
    });
  }

  // ── Employee advance REQUESTS (asked, not given) ────────────────────────────
  // One review sheet, opened either from the amber top card (everyone) or from a row's
  // ⏳ chip (that employee only). Approving PAYS immediately — the funding account is
  // chosen in the same modal "+ advance" uses, so cash/online/bank behave identically.
  let REQ_LIST = [];

  async function openRequestSheet(userId) {
    openSheet('Advance requests', 'Loading…');
    try {
      const res = await fetch('/hr/payroll/pending-requests', { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      REQ_LIST = j.requests || [];
      if (j.funding) FUND = j.funding;
      renderRequestSheet(userId ? Number(userId) : null);
    } catch (e) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">Could not load requests: ' + (e.message || e) + '</div>';
    }
  }

  function renderRequestSheet(userId) {
    const list = userId ? REQ_LIST.filter(x => Number(x.user_id) === userId) : REQ_LIST;
    el('prSheetTitle').textContent = userId && list.length
      ? 'Advance requests — ' + list[0].fullname
      : 'Advance requests';
    if (!list.length) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">No requests waiting.</div>';
      return;
    }
    const total = list.reduce((s, x) => s + Number(x.amount || 0), 0);
    let html = '<div style="padding:10px 14px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:11.5px;color:#92400e;">' +
      '<b>Nothing here has been paid yet.</b> Approving pays the money immediately from the account you pick, ' +
      'and it is then deducted from that person\'s next salary.</div>';
    html += list.map((q) => {
      const who = q.self_requested
        ? '<span style="color:#2563eb;">asked by ' + esc(q.fullname) + '</span>'
        : 'entered by ' + esc(q.raised_by || 'a manager');
      const age = q.age_days > 60
        ? '<span style="color:#b91c1c;font-weight:700;"> · ' + q.age_days + ' days old</span>'
        : (q.age_days > 0 ? '<span style="color:#9ca3af;"> · ' + q.age_days + 'd ago</span>' : '');
      return '<div class="pr-daterow" style="align-items:flex-start;">' +
        '<div style="min-width:0;">' +
          '<div class="dt">' + fmt(q.amount) + ' <span style="font-weight:600;color:#374151;font-size:12px;">· ' + esc(q.fullname) + '</span></div>' +
          '<div class="lb">' + (q.date || '') + ' · ' + esc(q.request_number) + age + '</div>' +
          '<div class="lb" style="color:#9ca3af;">' + who + '</div>' +
          (q.note ? '<div class="lb" style="color:#9ca3af;font-style:italic;">“' + esc(q.note) + '”</div>' : '') +
        '</div>' +
        '<div style="display:flex;gap:6px;flex-shrink:0;">' +
          '<button type="button" data-reqok="' + q.request_id + '" style="font-size:11px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;border-radius:6px;padding:5px 10px;cursor:pointer;white-space:nowrap;">✓ Approve &amp; pay</button>' +
          '<button type="button" data-reqno="' + q.request_id + '" style="font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:5px 10px;cursor:pointer;white-space:nowrap;">✕ Reject</button>' +
        '</div></div>';
    }).join('');
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;"><span class="dt">Total requested</span><span class="dt">' + fmt(total) + '</span></div>';
    el('prSheetBody').innerHTML = html;

    list.forEach((q) => {
      const okBtn = document.querySelector('[data-reqok="' + q.request_id + '"]');
      if (okBtn) okBtn.onclick = () => openApproveRequest(q);
      const noBtn = document.querySelector('[data-reqno="' + q.request_id + '"]');
      if (noBtn) noBtn.onclick = () => rejectRequest(q, userId);
    });
  }

  // Reuse the give-advance modal in APPROVE mode: amount is fixed by the request, the
  // manager only picks where the money comes from.
  function openApproveRequest(q) {
    ADV_MODE = 'approve';
    ADV_REQ = q;
    ADV_ROW = null;
    el('prAdvModalTitle').textContent = 'Approve & pay advance';
    el('prAdvWho').innerHTML = 'To <b>' + esc(q.fullname) + '</b> · ' + esc(q.request_number) +
      '<div style="font-size:11.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 8px;margin-top:6px;">' +
      'This pays <b>' + fmt(q.amount) + '</b> now and deducts it from the next salary.</div>';
    el('prAdvAmount').value = q.amount;
    el('prAdvAmount').readOnly = true;
    el('prAdvAmount').style.background = '#f3f4f6';
    el('prAdvNote').style.display = 'none';
    el('prAdvConfirm').textContent = 'Approve & pay';
    resetAdvFunding();
    el('prAdvModal').classList.add('show');
  }

  async function rejectRequest(q, filterUid) {
    const reason = prompt('Reject this request?\n\n' + fmt(q.amount) + ' for ' + q.fullname +
      (q.date ? ' (asked ' + q.date + ')' : '') +
      '\nNo money has been paid, so nothing is reversed.\n\nType the reason (required):', '');
    if (reason === null) return;
    if (!reason.trim() || reason.trim().length < 3) { alert('Please type a reason.'); return; }
    try {
      const res = await fetch('/hr/payroll/reject-request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ request_id: q.request_id, reason: reason.trim() })
      });
      const j = await res.json();
      alert(j.message || (j.success ? 'Rejected.' : 'Could not reject.'));
      if (j.success) {
        REQ_LIST = REQ_LIST.filter(x => x.request_id !== q.request_id);
        renderRequestSheet(filterUid || null);
        reloadActive();
      }
    } catch (e) { alert('Could not reject the request.'); }
  }

  // Void = un-do a wrongly-given advance: the money goes back to the account it came from
  // (same engine that posted it) and the advance disappears from payroll everywhere.
  async function voidAdvance(r, a) {
    // Name the BANK, not the shared 'Online Bank' chart account — that's the balance the
    // reviewer will see move (plain text here, not HTML: no esc()).
    const back = (a.is_online ? (a.bank || 'Online Bank') : a.source) || 'the funding account';
    const reason = prompt('Void this advance?\n\n' + fmt(a.amount) + ' given to ' + r.fullname +
      (a.date ? ' on ' + a.date : '') + (a.request_number ? ' (' + a.request_number + ')' : '') +
      '\n' + fmt(a.amount) + ' goes back to ' + back + '.' +
      '\n\nType the reason (required):', '');
    if (reason === null) return;
    if (!reason.trim() || reason.trim().length < 3) { alert('Please type why this advance is being voided.'); return; }
    try {
      const res = await fetch('/hr/payroll/void-advance', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ request_id: a.request_id, reason: reason.trim() })
      });
      const j = await res.json();
      alert(j.message || (j.success ? 'Advance voided.' : 'Could not void this advance.'));
      if (j.success) {
        // Drop it locally so the open sheet is instantly correct, then reload the grid
        // (net pay + deductions all move with it).
        r.advances = (r.advances || []).filter(x => x.request_id !== a.request_id);
        r.advance_total = Math.round((r.advances.reduce((s, x) => s + Number(x.amount || 0), 0)) * 100) / 100;
        renderAdvanceSheet();
        if (ADV_SHEET.mode === 'custom') { customLoad(); } else { load(); }
      }
    } catch (e) {
      alert('Could not void this advance.');
    }
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
    html += line('Paid from', paidFromLabel(d));
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
    if (d.notes) html += line('Note', esc(d.notes));
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
    if (el('prCustCashLabel')) el('prCustCashLabel').textContent = cashLbl;
    if (el('prCustBankSel')) el('prCustBankSel').innerHTML = bankOpts;
  }

  // ---- give advance ----
  let ADV_ROW = null;
  let ADV_MODE = 'give';   // 'give' = new advance, 'approve' = pay an employee's request
  let ADV_REQ = null;      // the request being approved (approve mode only)
  // Funding picker reset — shared by "give" and "approve & pay" so both post money the
  // same way (NF Cash, or the single ONLINE account tagged with the chosen bank).
  function resetAdvFunding() {
    document.querySelectorAll('[data-advfund]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-advfund="cash"]').classList.add('active');
    document.querySelector('input[name=prAdvFund][value=cash]').checked = true;
    el('prAdvBankSel').disabled = true;
    el('prAdvConfirm').disabled = false;
  }

  function openAdvance(r) {
    ADV_MODE = 'give';
    ADV_REQ = null;
    ADV_ROW = r;
    el('prAdvModalTitle').textContent = 'Give advance';
    el('prAdvWho').innerHTML = 'To <b>' + esc(r.fullname) + '</b>' + (r.advance_total > 0 ? ' · open advances ' + fmt(r.advance_total) : '');
    el('prAdvAmount').value = '';
    el('prAdvAmount').readOnly = false;
    el('prAdvAmount').style.background = '';
    el('prAdvNote').value = '';
    el('prAdvNote').style.display = '';
    el('prAdvConfirm').textContent = 'Give advance';
    resetAdvFunding();
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
    const isApprove = ADV_MODE === 'approve';
    if (!isApprove && !ADV_ROW) return;
    if (isApprove && !ADV_REQ) return;
    const amount = Number(el('prAdvAmount').value);
    if (!amount || amount < 1) { alert('Enter an amount.'); return; }
    const fundType = document.querySelector('input[name=prAdvFund]:checked').value;
    const bankId = el('prAdvBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Choose the bank.'); return; }
    el('prAdvConfirm').disabled = true;
    el('prAdvConfirm').textContent = isApprove ? 'Paying…' : 'Giving…';
    try {
      const url = isApprove ? '/hr/payroll/approve-request' : '/hr/payroll/give-advance';
      const body = isApprove
        ? { request_id: ADV_REQ.request_id, funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null }
        : { user_id: ADV_ROW.user_id, amount, funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null, note: el('prAdvNote').value || null };
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body)
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prAdvModal').classList.remove('show');
      if (isApprove) {
        REQ_LIST = REQ_LIST.filter(x => x.request_id !== ADV_REQ.request_id);
        el('prSheet').classList.remove('show');
        alert(j.message || 'Approved.');
      }
      await reloadActive();
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
      '<div class="r"><span>' + esc(r.fullname) + (hasNetOverride(r) ? ' <span style="color:#7c3aed;font-size:11px;">manual</span>' : '') + '</span><span>' + fmt(effNet(r)) + '</span></div>'
    ).join('') + '<div class="r" style="font-weight:700;background:#fafbfc;"><span>Total</span><span>' +
      fmt(sel.reduce((s, r) => s + effNet(r), 0)) + '</span></div>';
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
      items: sel.map(r => ({ user_id: r.user_id, net: effNet(r), late_deduction: lateDed(r), net_override: hasNetOverride(r) ? Math.max(0, Number(r._netOverride)) : null, skip_overtime: !!r._skipOvertime, skip_late_leave: !!r._skipLateLeave }))
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

  // ============================================================
  //  TABS + CUSTOM-SCHEDULE VIEW
  // ============================================================
  function reloadActive() { return TAB === 'custom' ? customLoad() : load(); }
  function switchTab(tab) {
    TAB = tab;
    el('prTabMonthly').classList.toggle('active', tab === 'monthly');
    el('prTabCustom').classList.toggle('active', tab === 'custom');
    el('prMonthlyView').classList.toggle('active', tab === 'monthly');
    el('prCustomView').classList.toggle('active', tab === 'custom');
    if (tab === 'custom') { el('prPaybar').classList.remove('show'); customLoad(); }
    else { load(); }
  }
  el('prTabMonthly').onclick = () => switchTab('monthly');
  el('prTabCustom').onclick = () => switchTab('custom');

  async function customLoad() {
    const month = el('prMonth').value;
    el('prCustList').innerHTML = '<div class="pr-empty">Loading…</div>';
    try {
      const res = await fetch('/hr/payroll/custom-data?month=' + encodeURIComponent(month), { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      CURMONTH = j.month;
      FUND = j.funding || { cash: null, banks: [] };
      SCHEDULE_AVAILABLE = !!j.schedule_available;
      KHAAS_AVAILABLE = !!j.khaas_available;
      KHAAS_BU_ID = j.khaas_bu_id || null;
      CAN_VOID = !!j.can_void_advance;
      CUST_ROWS = j.rows || [];
      CUST_LOADED_MONTH = month;
      buildFundModal();
      renderCustom();
    } catch (e) {
      el('prCustList').innerHTML = '<div class="pr-empty">Could not load: ' + (e.message || e) + '</div>';
    }
  }

  function renderCustom() {
    if (!SCHEDULE_AVAILABLE) {
      el('prCustList').innerHTML = '<div class="pr-empty">Custom schedules aren\'t enabled yet.</div>';
      return;
    }
    if (!CUST_ROWS.length) {
      el('prCustList').innerHTML = '<div class="pr-empty">No custom-schedule employees yet. Use ⚙ settings on the Monthly tab to move someone here.</div>';
      return;
    }
    el('prCustList').innerHTML = CUST_ROWS.map((r, i) => custCard(r, i)).join('');
    CUST_ROWS.forEach((r, i) => wireCust(r, i));
  }

  function custCard(r, i) {
    const unit = r.rate_type === 'daily' ? '/ day' : '/ month';
    const rate = r.configured
      ? '<span class="pr-rate" data-crate="' + i + '"><span class="amt">' + fmt(r.base_rate) + '</span><span class="unit">' + unit + '</span><span style="color:#9ca3af;font-size:11px;">✎</span></span>'
      : '<span class="pr-setsal" data-crate="' + i + '">＋ Set rate</span>';
    const buChip = KHAAS_AVAILABLE
      ? '<span class="pr-bu bu-' + (r.bu_code === 'KHAAS' ? 'KHAAS">Khaas' : 'NF">NF') + '</span>'
      : '';

    let cover = (r.paid_periods || []).map((p, pi) =>
      '<span class="pr-cover-chip paid" data-period="' + i + '_' + pi + '" title="Paid ' + esc(String(p.paid_at).slice(0, 10)) +
        (paidFromLabel(p) ? ' from ' + paidFromLabel(p) : '') + '">✓ ' + esc(p.label) + ' · ' + fmt(p.net) + '</span>'
    ).join('');
    if (!r.paid_periods || !r.paid_periods.length) {
      cover += '<span class="pr-cover-chip none">Nothing paid in this month yet</span>';
    }
    cover += '<button class="pr-cover-add" data-addperiod="' + i + '">＋ Add period</button>';

    // Open advances: the amount opens the same list the Monthly tab shows (each
    // advance's amount, date and request number) — the manager can see WHAT is open,
    // not just the total. `data-cadv` (not `data-adv`) because both views live in the
    // DOM at once and a shared attribute would wire the monthly row's element.
    const adv = r.advance_total > 0
      ? '<div class="pr-cust-adv">Open advances: <span class="view" data-cadv="' + i + '">' + fmt(r.advance_total) + '</span> (deducted next pay) <span class="give" data-cgive="' + i + '">＋ advance</span></div>'
      : '<div class="pr-cust-adv" style="color:#9ca3af;">No open advances <span class="give" data-cgive="' + i + '">＋ advance</span></div>';

    return '<div class="pr-cust-card">' +
      '<div class="pr-cust-top">' +
        '<div><div class="pr-cust-name">' + esc(r.fullname) +
          (r.designation ? ' <span style="font-weight:400;color:#9ca3af;font-size:12px;">· ' + esc(r.designation) + '</span>' : '') + '</div>' +
          '<div class="pr-cust-meta">' + rate + buChip +
            '<span class="pr-gear" data-csettings="' + i + '" title="Pay schedule & business unit">⚙ settings</span></div></div>' +
        '<div style="text-align:right;"><div style="font-size:11px;color:#9ca3af;">Paid in ' + esc(monthLabel()) + '</div>' +
          '<div style="font-weight:700;">' + fmt(r.paid_total || 0) + '</div></div>' +
      '</div>' +
      '<div class="pr-cover">' + cover + '</div>' +
      adv +
    '</div>';
  }
  function monthLabel() {
    const [y, m] = (CURMONTH || el('prMonth').value).split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
  }

  function wireCust(r, i) {
    const rate = document.querySelector('[data-crate="' + i + '"]');
    if (rate) rate.onclick = () => editRate(r);
    const gear = document.querySelector('[data-csettings="' + i + '"]');
    if (gear) gear.onclick = () => openSettings(r);
    const add = document.querySelector('[data-addperiod="' + i + '"]');
    if (add) add.onclick = () => openCustomPay(r);
    const give = document.querySelector('[data-cgive="' + i + '"]');
    if (give) give.onclick = () => openAdvance(r);
    const cadv = document.querySelector('[data-cadv="' + i + '"]');
    if (cadv) cadv.onclick = () => showAdvances(r, 'custom');
    (r.paid_periods || []).forEach((p, pi) => {
      const chip = document.querySelector('[data-period="' + i + '_' + pi + '"]');
      if (chip) chip.onclick = () => showCustomPeriod(r, p);
    });
  }

  // Rate edit reuses the base-salary endpoint (base_salary IS the rate for custom).
  function editRate(r) {
    const cur = r.configured ? Math.round(r.base_rate) : '';
    const val = prompt('Rate for ' + r.fullname + ' (' + (r.rate_type === 'daily' ? 'per DAY' : 'per MONTH') + '):', cur);
    if (val === null) return;
    const num = Number(val);
    if (String(val).trim() === '' || isNaN(num) || num < 0) { alert('Enter a valid amount.'); return; }
    fetch('/hr/payroll/set-salary', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ user_id: r.user_id, base_salary: num })
    }).then(res => res.json()).then(j => {
      if (!j.success) throw new Error(j.message || 'Failed');
      customLoad();
    }).catch(e => alert('Could not save rate: ' + (e.message || e)));
  }

  // The frozen receipt for a paid period — the same story the Monthly tab tells:
  // what the period earned, what advance was recovered from it, what was handed
  // over, from which account and by whom. Every figure comes from the payment row.
  function showCustomPeriod(r, p) {
    const line = (label, value, neg, muted) =>
      '<div class="pr-daterow"><span class="dt" style="font-weight:400;color:#6b7280;">' + label + '</span>' +
      '<span class="dt"' + (neg ? ' style="color:#b91c1c;"' : (muted ? ' style="color:#cbd5e1;"' : '')) + '>' + value + '</span></div>';

    const adv = Number(p.advance_total || 0);
    const gross = (p.gross !== undefined && p.gross !== null) ? Number(p.gross) : (Number(p.net || 0) + adv);
    let html = '';
    html += line('Period', esc(p.label));
    html += line('Paid on', p.paid_at ? esc(String(p.paid_at).slice(0, 16)) : '—');
    if (p.paid_by_name) html += line('Paid by', esc(p.paid_by_name));
    html += line('Paid from', paidFromLabel(p));
    html += '<div style="height:8px;"></div>';
    if (p.days_paid) html += line('Days paid', p.days_paid + ' day' + (p.days_paid === 1 ? '' : 's'));
    html += line('Amount for the period', fmt(gross));
    html += line('Advances recovered', adv > 0 ? '− ' + fmt(adv) : 'none', adv > 0, adv === 0);
    if (p.present_days !== undefined && p.present_days !== null) {
      html += line('Attendance (reference)', p.present_days + ' present · ' + p.absent_days + ' absent');
    }
    if (p.notes) html += line('Note', esc(p.notes));
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;margin-top:4px;"><span class="dt">Net paid</span><span class="dt" style="color:#047857;">' + fmt(p.net) + '</span></div>';
    html += '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">' +
      (p.ledger_id ? ('Ledger entry #' + p.ledger_id + '. ')
                   : (adv > 0 ? 'No cash moved — the whole amount went to recovering advances. ' : '')) +
      'This range is settled; overlapping days are blocked for new periods.</div>';

    el('prSheetTitle').textContent = 'Period — ' + r.fullname;
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
  }

  // ---- settings modal (schedule + business unit) ----
  let SET_ROW = null, SET_SCHED = 'monthly', SET_RATE = 'monthly', SET_BU = 'NF';
  function setRadioGroup(containerId, attr, value) {
    document.querySelectorAll('#' + containerId + ' [data-' + attr + ']').forEach(x => {
      x.classList.toggle('active', x.getAttribute('data-' + attr) === value);
    });
  }
  function openSettings(r) {
    SET_ROW = r;
    SET_SCHED = r.pay_schedule === 'custom' ? 'custom' : 'monthly';
    SET_RATE = r.rate_type === 'daily' ? 'daily' : 'monthly';
    SET_BU = r.bu_code === 'KHAAS' ? 'KHAAS' : 'NF';
    el('prSetTitle').textContent = 'Pay settings — ' + r.fullname;
    setRadioGroup('prSetSched', 'sched', SET_SCHED);
    setRadioGroup('prSetRate', 'rate', SET_RATE);
    setRadioGroup('prSetBu', 'bu', SET_BU);
    el('prSetRateWrap').style.display = SET_SCHED === 'custom' ? 'block' : 'none';
    el('prSetBuWrap').style.display = KHAAS_AVAILABLE ? 'block' : 'none';
    el('prSetModal').classList.add('show');
  }
  document.querySelectorAll('#prSetSched [data-sched]').forEach(x => x.onclick = () => {
    SET_SCHED = x.getAttribute('data-sched'); setRadioGroup('prSetSched', 'sched', SET_SCHED);
    el('prSetRateWrap').style.display = SET_SCHED === 'custom' ? 'block' : 'none';
  });
  document.querySelectorAll('#prSetRate [data-rate]').forEach(x => x.onclick = () => {
    SET_RATE = x.getAttribute('data-rate'); setRadioGroup('prSetRate', 'rate', SET_RATE);
  });
  document.querySelectorAll('#prSetBu [data-bu]').forEach(x => x.onclick = () => {
    SET_BU = x.getAttribute('data-bu'); setRadioGroup('prSetBu', 'bu', SET_BU);
  });
  el('prSetCancel').onclick = () => el('prSetModal').classList.remove('show');
  el('prSetModal').onclick = (ev) => { if (ev.target === el('prSetModal')) el('prSetModal').classList.remove('show'); };
  el('prSetSave').onclick = async () => {
    if (!SET_ROW) return;
    el('prSetSave').disabled = true; el('prSetSave').textContent = 'Saving…';
    try {
      const r1 = await fetch('/hr/payroll/set-schedule', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: SET_ROW.user_id, pay_schedule: SET_SCHED, rate_type: SET_RATE })
      }).then(x => x.json());
      if (!r1.success) throw new Error(r1.message || 'Failed');
      if (KHAAS_AVAILABLE) {
        const r2 = await fetch('/hr/payroll/set-business-unit', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ user_id: SET_ROW.user_id, business_unit_id: SET_BU === 'KHAAS' ? KHAAS_BU_ID : null })
        }).then(x => x.json());
        if (!r2.success) throw new Error(r2.message || 'Failed');
      }
      el('prSetModal').classList.remove('show');
      reloadActive();
    } catch (e) {
      alert('Could not save: ' + (e.message || e));
    } finally {
      el('prSetSave').disabled = false; el('prSetSave').textContent = 'Save';
    }
  };

  // ---- custom period pay modal ----
  let CUST_ROW = null;
  function openCustomPay(r) {
    if (!r.configured) { alert('Set this employee\'s rate first (click the rate).'); return; }
    CUST_ROW = r;
    el('prCustTitle').textContent = 'Add period — ' + r.fullname;
    el('prCustStart').value = r.suggested_start || '';
    el('prCustEnd').value = '';
    el('prCustAmount').value = '';
    el('prCustNote').value = '';
    el('prCustCalcInner').innerHTML = '<div class="row"><span>Pick the end date to see the amount</span><span></span></div>';
    document.querySelectorAll('[data-custfund]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-custfund="cash"]').classList.add('active');
    document.querySelector('input[name=prCustFund][value=cash]').checked = true;
    el('prCustBankSel').disabled = true;
    el('prCustModal').classList.add('show');
  }
  async function custPreview() {
    if (!CUST_ROW) return;
    const start = el('prCustStart').value, end = el('prCustEnd').value;
    if (!start || !end) return;
    el('prCustCalcInner').innerHTML = '<div class="row"><span>Calculating…</span><span></span></div>';
    try {
      const res = await fetch('/hr/payroll/custom-preview', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: CUST_ROW.user_id, start, end })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      const row = j.row;
      const unit = row.rate_type === 'daily'
        ? (fmt(row.base_rate) + '/day × ' + row.days + ' day' + (row.days === 1 ? '' : 's'))
        : (fmt(row.base_rate) + '/mo ÷ 30 × ' + row.days + ' day' + (row.days === 1 ? '' : 's'));
      let html = '<div class="row"><span>' + unit + '</span><span>' + fmt(row.computed_amount) + '</span></div>';
      if (row.advance_total > 0) html += '<div class="row"><span>Advances recovered this pay</span><span>− ' + fmt(row.advance_total) + '</span></div>';
      html += '<div class="row tot"><span>Net at computed amount</span><span>' + fmt(row.net_amount) + '</span></div>';
      if (row.advance_open_after > 0) html += '<div class="ref" style="color:#b45309;">' + fmt(row.advance_open_after) + ' of advances is bigger than this pay — it stays open for a later payment.</div>';
      html += '<div class="ref">In range: ' + row.present_days + ' present · ' + row.absent_days + ' absent (reference only — does not change pay)</div>';
      el('prCustCalcInner').innerHTML = html;
      if (el('prCustAmount').value.trim() === '') el('prCustAmount').value = Math.round(row.computed_amount);
    } catch (e) {
      el('prCustCalcInner').innerHTML = '<div class="row"><span style="color:#b91c1c;">' + esc(e.message || String(e)) + '</span><span></span></div>';
    }
  }
  el('prCustStart').onchange = () => { el('prCustAmount').value = ''; custPreview(); };
  el('prCustEnd').onchange = () => { el('prCustAmount').value = ''; custPreview(); };
  document.querySelectorAll('[data-custfund]').forEach(f => {
    f.onclick = (ev) => {
      if (ev.target.closest('select')) return;
      document.querySelectorAll('[data-custfund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-custfund') !== 'online');
      if (el('prCustBankSel').disabled !== dis) el('prCustBankSel').disabled = dis;
    };
  });
  el('prCustCancel').onclick = () => el('prCustModal').classList.remove('show');
  el('prCustModal').onclick = (ev) => { if (ev.target === el('prCustModal')) el('prCustModal').classList.remove('show'); };
  el('prCustConfirm').onclick = async () => {
    if (!CUST_ROW) return;
    const start = el('prCustStart').value, end = el('prCustEnd').value;
    if (!start || !end) { alert('Pick the start and end dates.'); return; }
    const fundType = document.querySelector('input[name=prCustFund]:checked').value;
    const bankId = el('prCustBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Choose the bank.'); return; }
    const amount = el('prCustAmount').value.trim();
    el('prCustConfirm').disabled = true; el('prCustConfirm').textContent = 'Paying…';
    try {
      const res = await fetch('/hr/payroll/custom-pay', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          user_id: CUST_ROW.user_id, start, end,
          funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null,
          amount: amount === '' ? null : Number(amount), note: el('prCustNote').value || null
        })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prCustModal').classList.remove('show');
      alert('Paid ' + fmt(j.net) + '.');
      customLoad();
    } catch (e) {
      alert('Could not pay: ' + (e.message || e));
    } finally {
      el('prCustConfirm').disabled = false; el('prCustConfirm').textContent = 'Pay period';
    }
  };

  // auto-load current month
  load();
})();
</script>
@endsection
