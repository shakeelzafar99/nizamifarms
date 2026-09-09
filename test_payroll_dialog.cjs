/**
 * 🧾 Pay-dialog copy checks for the WEB payroll page (Sep-2026).
 *
 * The shortfall box is pure browser JS living inside a 3,700-line Blade file, so nothing on the
 * PHP side can prove what a manager actually reads. This harness lifts the REAL source out of
 * the blade — the helpers plus the Pay-button handler, by name, never a copy — runs it against
 * a stub DOM, and asserts the sentences that come out.
 *
 * Owner rulings it protects (Sep-7 2026):
 *   • an advance is only ever DEDUCTED, never repaid → the uncovered part MOVES to next month,
 *     and that is the default;
 *   • the payer is TOLD the amount and the month, and can still choose to write it off;
 *   • both options pay the employee the same Rs 0, and the copy says so first.
 *
 *   node test_payroll_dialog.cjs
 */
const fs = require('fs');
const path = require('path');

const BLADE = path.join(__dirname, 'resources/views/pages/payroll/index.blade.php');
const src = fs.readFileSync(BLADE, 'utf8');

let pass = 0, fail = 0;
const check = (what, got, want) => {
  if (JSON.stringify(got) === JSON.stringify(want)) { pass++; console.log('  ok   ' + what); }
  else { fail++; console.log('  FAIL ' + what + '\n         got  ' + JSON.stringify(got) + '\n         want ' + JSON.stringify(want)); }
};
const has = (what, hay, needle) => check(what, String(hay).includes(needle), true);
const hasNot = (what, hay, needle) => check(what, String(hay).includes(needle), false);

// ── Lift the real source ───────────────────────────────────────────────────
function slice(start, end) {
  const i = src.indexOf(start);
  if (i < 0) { throw new Error('not found in blade: ' + start); }
  const j = src.indexOf(end, i);
  if (j < 0) { throw new Error('end not found for: ' + start); }
  return src.slice(i, j + end.length);
}
const helpers = [
  slice("const fmt = (n) =>", "\n"),
  slice("function lateDed(r) {", "\n  }"),
  slice("function net(r) {", "\n  }"),
  slice("function hasNetOverride(r)", "\n"),
  slice("function effNet(r)", "\n"),
  slice("function laFor(r, kind)", "\n"),
  slice("function esc(s) {", "\n"),
].join('\n');
const handler = slice("el('prPayBtn').onclick = () => {", "\n    el('prPayModal').classList.add('show');\n  };");

// ── A DOM just big enough to run it ────────────────────────────────────────
function makeDom() {
  const nodes = {};
  const node = (id) => (nodes[id] = nodes[id] || {
    id, textContent: '', innerHTML: '', style: {}, disabled: false,
    classList: {add() {}, remove() {}, toggle() {}},
  });
  // Radio groups behave like the browser's: checking one unchecks its siblings.
  const group = (values, first) => {
    const list = values.map((value) => ({value, disabled: false, _c: value === first}));
    list.forEach((r) => Object.defineProperty(r, 'checked', {
      get() { return r._c; },
      set(v) { if (v) { list.forEach((o) => { o._c = false; }); } r._c = !!v; },
    }));
    return list;
  };
  const radios = {
    prShortMode: group(['carry', 'writeoff'], 'carry'),
    prLeaveMode: group(['apply', 'defer'], 'apply'),
  };
  const document = {
    getElementById: node,
    querySelector(sel) {
      const m = sel.match(/input\[name=(\w+)\]\[value=(\w+)\]/);
      if (m) { return (radios[m[1]] || []).find((r) => r.value === m[2]) || null; }
      const c = sel.match(/input\[name=(\w+)\]:checked/);
      if (c) { return (radios[c[1]] || []).find((r) => r.checked) || null; }
      return null;
    },
    querySelectorAll: () => [],
  };
  return {node, radios, document};
}

/** Run the real handler over these selected rows (grid month = August 2026). */
function openDialog(rows) {
  const dom = makeDom();
  const fn = new Function('document', 'el', 'selectedRows', 'CURMONTH',
    helpers + '\n' + handler + '\n return el("prPayBtn").onclick;');
  fn(dom.document, dom.node, () => rows, '2026-08')();
  const n = dom.node;
  const checked = dom.radios.prShortMode.find((r) => r.checked);
  return {
    head: n('prShortHead').textContent,
    list: n('prShortList').innerHTML,
    carryLabel: n('prShortOptCarryLbl').textContent,
    outCarry: n('prShortOutCarry').textContent,
    outA: n('prShortOutA').textContent,
    foot: n('prShortFoot').innerHTML,
    boxShown: n('prShortBox').style.display !== 'none',
    carryDisabled: dom.radios.prShortMode[0].disabled,
    defaultChoice: checked ? checked.value : null,
    absWarn: n('prPayAbsWarn').innerHTML,
    absWarnShown: n('prPayAbsWarn').style.display !== 'none',
    total: n('prPayList').innerHTML,
  };
}

const row = (over = {}) => ({
  user_id: 21, fullname: 'Kanan Anoos', base_salary: 45000,
  absent_days: 0, absent_deduction: 0, late_deduction: 0, late_leave_deduct: 0,
  advance_total: 0, held_absence_deduction: 0, bonuses: 0, allowances: 0, other: 0,
  absence_decision: null, bonus_leaves: 0, leave_actions: [], carry_available: true,
  net_salary: 45000, net_raw: 45000, _lateOverride: null, _netOverride: null,
  ...over,
});

// Kanan's August: 1 absent day (Rs 129) and an advance the month cannot absorb.
const SHORT = row({
  absent_days: 1, absent_deduction: 129,
  advance_total: 61400, net_salary: 0, net_raw: -16529,
});

console.log('1. A shortfall month tells the payer the amount and the month it moves to');
let d = openDialog([SHORT]);
check('the box is shown', d.boxShown, true);
has('headline names the person and the gap', d.head, 'Kanan Anoos owes Rs 16,529 more than this month’s salary covers');
check('carry is the default', d.defaultChoice, 'carry');
check('the option names the amount and the month', d.carryLabel, 'Move Rs 16,529 to September 2026');
check('and its outcome', d.outCarry, 'He takes home Rs 0 · deducted from September 2026’s pay');
check('write-off outcome', d.outA, 'He takes home Rs 0 · Rs 16,529 never recovered');
check('carry is usable', d.carryDisabled, false);
has('the footer explains the advance stays open', d.foot, 'stays open until salaries have taken all of it');

console.log('\n2. The old wording is gone');
hasNot('no "Proceed anyway"', JSON.stringify(d), 'Proceed anyway');
hasNot('no "Don’t deduct"', JSON.stringify(d), 'Don’t deduct');
hasNot('no "closed in full or not at all"', d.foot, 'closed in full');

console.log('\n3. Before the carry SQL is on prod, only write-off is offered');
d = openDialog([row({...SHORT, carry_available: false})]);
check('carry disabled', d.carryDisabled, true);
has('and says why', d.outCarry, 'database update');
check('write-off becomes the default', d.defaultChoice, 'writeoff');

console.log('\n4. A gap that is not an advance has nothing to move');
// A held charge for earlier parked days, larger than the salary, with no advance open.
d = openDialog([row({held_absence_deduction: 50000, net_salary: 0, net_raw: -5000})]);
check('carry disabled', d.carryDisabled, true);
has('says so plainly', d.outCarry, 'Nothing to move');
check('write-off is the default', d.defaultChoice, 'writeoff');

console.log('\n5. Part advance, part held charge: moves what it can, says what it cannot');
d = openDialog([row({advance_total: 10000, held_absence_deduction: 40000, net_salary: 0, net_raw: -5000})]);
check('moves the advance part', d.carryLabel, 'Move Rs 5,000 to September 2026');
d = openDialog([row({advance_total: 3000, held_absence_deduction: 47000, net_salary: 0, net_raw: -5000})]);
check('moves only the Rs 3,000 that is advance', d.carryLabel, 'Move Rs 3,000 to September 2026');
has('and names the Rs 2,000 written off either way', d.outCarry, 'Rs 2,000 still written off');

console.log('\n6. Two people short read as two people');
d = openDialog([SHORT, row({user_id: 22, fullname: 'Sabir', advance_total: 115000, net_salary: 0, net_raw: -70000})]);
has('headline counts them', d.head, '2 employees owe Rs 86,529 more');
has('and each is named with his own gap', d.list, 'Sabir — short by <b>Rs 70,000</b>');
has('plural take-home', d.outCarry, 'They take home Rs 0');
check('the moved total is both', d.carryLabel, 'Move Rs 86,529 to September 2026');

console.log('\n7. An ordinary month shows none of it');
d = openDialog([row()]);
check('box hidden', d.boxShown, false);

console.log('\n8. Undecided absences are still named before the money moves');
d = openDialog([row({absent_days: 2, absent_deduction: 3460, net_salary: 41540, net_raw: 41540})]);
check('warning shown', d.absWarnShown, true);
has('names the person and the days', d.absWarn, 'Kanan Anoos (2)');

console.log('\n9. The total the manager confirms matches what the server will pay');
d = openDialog([row({held_absence_deduction: 3200, net_salary: 41800, net_raw: 41800})]);
has('a held charge is inside the total', d.total, 'Rs 41,800');
hasNot('not the pre-charge figure', d.total, 'Rs 45,000');

console.log('\n' + '─'.repeat(60));
console.log(fail === 0 ? `ALL GREEN  (${pass} passed)` : `${fail} FAILED  (${pass} passed)`);
process.exit(fail === 0 ? 0 : 1);
