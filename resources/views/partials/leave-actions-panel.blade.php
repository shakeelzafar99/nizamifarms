{{--
  THE leave-actions row renderer — shared by the payroll page and the attendance page.

  Why this file exists (Sep-6 2026): the attendance page carried a HAND-COPIED clone of
  payroll's panel. It had drifted — it had no absence branch at all, so it offered
  "Deduct / Keep leave" for an absence, posted a decision the service always refuses, and
  labelled a parked month "leaves deducted". Two copies of one screen is the bug; this is
  the single copy.

  It also fixes the thing that made the panel hard to read: a decided row used to keep all
  of its buttons (absence) or replace them with a blind two-state flip (overtime / late).
  Now ONE pattern everywhere — decided rows show a single chip with who and when, and
  "change" re-opens the very same buttons with the current one marked.

  All wording and every button label comes from the SERVER (`choices`, `decided_label`),
  so no surface can invent its own vocabulary. See PayrollService::leaveActionShape().

  Usage:
      NFLeaveActions.render(bodyEl, items, {
        monthOpen, monthLabel, onDecide(item, choice), onDrill(item), header
      });
  `items` is a flat list of actions, each carrying user_id / fullname / paid.
--}}
@once
@push('custom_css')
<style>
  /* Every class is prefixed — a bare name here would collide with the global utility sheet. */
  .nfla-row   { padding: 11px 14px; border-bottom: 1px solid #f6f7f8; }
  .nfla-name  { font-size: 13px; font-weight: 700; color: #111827; }
  .nfla-paid  { font-size: 10px; font-weight: 700; color: #6b7280; background: #f3f4f6; border-radius: 5px; padding: 1px 6px; margin-left: 6px; }
  .nfla-head  { font-size: 12.5px; font-weight: 700; margin-top: 2px; }
  .nfla-why   { font-size: 11px; color: #6b7280; line-height: 1.5; margin-top: 2px; }
  .nfla-link  { color: #4f46e5; cursor: pointer; text-decoration: underline dotted; font-weight: 600; white-space: nowrap; }
  .nfla-acts  { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; align-items: center; }
  .nfla-btn   { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 4px 11px; cursor: pointer; border: 1px solid transparent; white-space: nowrap; background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
  .nfla-btn:disabled { opacity: .5; cursor: not-allowed; }
  .nfla-btn.nfla-t-good   { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
  .nfla-btn.nfla-t-danger { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
  .nfla-btn.nfla-t-hold   { color: #b45309; background: #fffbeb; border-color: #fcd34d; }
  .nfla-btn.nfla-t-muted  { color: #6b7280; background: #f3f4f6; border-color: #e5e7eb; }
  /* The one in force, while the buttons are open for a change. */
  .nfla-btn.nfla-cur { outline: 2px solid currentColor; outline-offset: 1px; font-weight: 800; }
  .nfla-chip  { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 3px 9px; display: inline-block; }
  .nfla-chip.nfla-t-good   { color: #047857; background: #ecfdf5; }
  .nfla-chip.nfla-t-danger { color: #b91c1c; background: #fef2f2; }
  .nfla-chip.nfla-t-hold   { color: #b45309; background: #fffbeb; }
  .nfla-chip.nfla-t-muted  { color: #6b7280; background: #f3f4f6; }
  .nfla-who   { font-size: 10.5px; color: #9ca3af; }
  .nfla-wait  { font-size: 11px; color: #6b7280; font-style: italic; }
  .nfla-warn  { font-size: 10.5px; color: #b45309; margin-top: 3px; }
  .nfla-hint  { font-size: 10.5px; color: #9ca3af; margin-top: 4px; width: 100%; }
  .nfla-empty { padding: 40px; text-align: center; color: #9ca3af; }
</style>
@endpush

<script>
(function () {
  'use strict';
  if (window.NFLeaveActions) { return; }

  var esc = function (s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  };

  var ITEMS = [];
  var OPTS = {};
  var OPEN_CHANGE = {};   // idx -> true while a decided row has its buttons re-opened

  var tone = function (t) { return 'nfla-t-' + (t || 'muted'); };

  // An absence can always be decided (the days already happened); overtime and lateness
  // only once the month can no longer change.
  function decidable(item) {
    return item.kind === 'absence' || !OPTS.monthOpen;
  }

  function buttonsHtml(item, idx) {
    var cur = item.current_choice || null;
    var list = item.choices || [];
    var html = list.map(function (c) {
      var isCur = cur !== null && c.value === cur;
      return '<button type="button" class="nfla-btn ' + tone(c.tone) + (isCur ? ' nfla-cur' : '') +
        '" data-nfla-dec="' + idx + '" data-nfla-choice="' + esc(c.value) + '"' +
        (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + '</button>';
    }).join('');
    // Hints are what the tooltips used to hide. The one that costs money is always shown.
    var hint = '';
    list.forEach(function (c) {
      if (c.value === 'waive' && c.hint && /forfeit/i.test(c.hint)) {
        hint = '<div class="nfla-hint">⚠ ' + esc(c.hint) + '</div>';
      }
    });
    if (!hint && item.kind === 'overtime' && Number(item.will_cover || 0) > 0) {
      hint = '<div class="nfla-hint">⚠ ' + item.will_cover +
        ' of these will settle parked absences instead of becoming leave</div>';
    }
    return html + hint;
  }

  function actsHtml(item, idx) {
    var settled = item.status && item.status !== 'pending';

    if (!settled) {
      if (!decidable(item)) {
        // No dead greyed pills. A disabled button with a tooltip reads as "broken";
        // a sentence reads as "not yet".
        return '<span class="nfla-wait">Can be decided once ' + esc(OPTS.monthLabel || 'the month') + ' ends</span>';
      }
      return buttonsHtml(item, idx) +
        (item.kind === 'absence'
          ? '<span style="font-size:10.5px;color:#b91c1c;">undecided — Pay will deduct it</span>'
          : '');
    }

    // Decided. One chip, always with who and when — then an explicit way back in.
    if (OPEN_CHANGE[idx]) {
      return buttonsHtml(item, idx) +
        '<span class="nfla-link" data-nfla-cancel="' + idx + '">cancel</span>';
    }
    var who = [item.decided_by ? 'by ' + esc(item.decided_by) : '', item.decided_at || '']
      .filter(Boolean).join(' · ');
    return '<span class="nfla-chip ' + tone(item.decided_tone) + '">' + esc(item.decided_label) + '</span>' +
      (who ? '<span class="nfla-who">' + who + '</span>' : '') +
      (decidable(item)
        ? '<span class="nfla-link" data-nfla-change="' + idx + '" style="margin-left:auto;">change ›</span>'
        : '');
  }

  function rowHtml(item, idx) {
    var col = item.kind === 'overtime' ? '#047857' : (item.kind === 'absence' ? '#b91c1c' : '#b45309');
    var paid = item.paid ? '<span class="nfla-paid">salary paid</span>' : '';
    var changed = item.changed
      ? '<div class="nfla-warn">⚠ recommended now: ' +
          (item.recommended_days > 0 ? '+' : '') + item.recommended_days +
          ' — this month was settled on a different figure.</div>'
      : '';
    return '<div class="nfla-row">' +
      '<div class="nfla-name">' + esc(item.fullname) + paid + '</div>' +
      '<div class="nfla-head" style="color:' + col + ';">' + esc(item.headline) + '</div>' +
      '<div class="nfla-why">' + esc(item.basis) + '<br>' + esc(item.formula) +
        (OPTS.onDrill ? ' · <span class="nfla-link" data-nfla-why="' + idx + '">see the days ›</span>' : '') +
      '</div>' +
      changed +
      '<div class="nfla-acts">' + actsHtml(item, idx) + '</div>' +
    '</div>';
  }

  function wire(root) {
    root.querySelectorAll('[data-nfla-why]').forEach(function (w) {
      w.onclick = function () {
        var it = ITEMS[Number(w.getAttribute('data-nfla-why'))];
        if (it && OPTS.onDrill) { OPTS.onDrill(it); }
      };
    });
    root.querySelectorAll('[data-nfla-change]').forEach(function (c) {
      c.onclick = function () {
        OPEN_CHANGE[Number(c.getAttribute('data-nfla-change'))] = true;
        paint(root);
      };
    });
    root.querySelectorAll('[data-nfla-cancel]').forEach(function (c) {
      c.onclick = function () {
        delete OPEN_CHANGE[Number(c.getAttribute('data-nfla-cancel'))];
        paint(root);
      };
    });
    root.querySelectorAll('[data-nfla-dec]').forEach(function (b) {
      b.onclick = function () {
        var idx = Number(b.getAttribute('data-nfla-dec'));
        var it = ITEMS[idx];
        var choice = b.getAttribute('data-nfla-choice');
        if (!it || !OPTS.onDecide) { return; }
        // Re-picking what is already in force is a no-op, not a round trip.
        if (it.current_choice && it.current_choice === choice) {
          delete OPEN_CHANGE[idx];
          paint(root);
          return;
        }
        if (it.status && it.status !== 'pending') {
          var was = (it.decided_label || 'the current decision').replace(/^[✓✕🅿]\s*/, '');
          var to = (it.choices || []).filter(function (c) { return c.value === choice; })[0];
          if (!confirm('Change ' + it.fullname + '’s ' + it.headline + '?\n\n' +
                       'Now: ' + was + '\nChange to: ' + ((to && to.label) || choice))) { return; }
        }
        root.querySelectorAll('.nfla-btn').forEach(function (x) { x.disabled = true; });
        delete OPEN_CHANGE[idx];
        OPTS.onDecide(it, choice);
      };
    });
  }

  function paint(root) {
    root.innerHTML = (OPTS.header || '') +
      (ITEMS.length
        ? ITEMS.map(rowHtml).join('')
        : '<div class="nfla-empty">Nothing to decide here.</div>');
    if (OPTS.afterPaint) { OPTS.afterPaint(root); }
    wire(root);
  }

  window.NFLeaveActions = {
    /** items = flat action list, each carrying user_id / fullname / paid. */
    render: function (root, items, opts) {
      ITEMS = items || [];
      OPTS = opts || {};
      OPEN_CHANGE = {};
      paint(root);
    },
    /** The items currently on screen (same indexes the data attributes use). */
    items: function () { return ITEMS; },
    esc: esc
  };
})();
</script>
@endonce
