/**
 * RIDER LENS — a day he rode TWO machines (Aug-29 2026).
 *
 * The reported problem: on a day Danish rode DCR-799 and then CEN-455, the rider
 * drill-down showed ONE bike beside the date (the last one) with the OTHER bike's
 * odometer printed next to it, and his two fuel claims rendered identically with
 * nothing saying which tank each filled.
 *
 * These lift the real renderers out of the blade and run them against the payload
 * shape the server now sends.
 *
 * Run:  node test_rider_two_bike_day.cjs
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
function ok(what, cond) {
  if (cond) { pass++; console.log('  ok  ' + what); }
  else { fail++; console.log('  XX  ' + what); }
}
function head(t) { console.log('\n== ' + t + ' =='); }

/** Comment/regex-aware brace lift (a bare quote scanner trips on apostrophes). */
function lift(src, name) {
  const start = src.indexOf('function ' + name + '(');
  if (start === -1) throw new Error('not found: ' + name);
  const open = src.indexOf('{', start);
  let depth = 0;
  const REGEX_OK = /[(,=:[!&|?{};+\-*%~^]\s*$/;
  for (let j = open; j < src.length; j++) {
    const c = src[j], n = src[j + 1];
    if (c === '/' && n === '/') { j = src.indexOf('\n', j); if (j === -1) break; continue; }
    if (c === '/' && n === '*') { j = src.indexOf('*/', j + 2); if (j === -1) break; j++; continue; }
    if (c === '"' || c === "'" || c === '`') {
      const q = c;
      for (j++; j < src.length; j++) { if (src[j] === '\\') { j++; continue; } if (src[j] === q) break; }
      continue;
    }
    if (c === '/' && REGEX_OK.test(src.slice(Math.max(0, j - 40), j))) {
      for (j++; j < src.length; j++) {
        if (src[j] === '\\') { j++; continue; }
        if (src[j] === '[') { while (j < src.length && src[j] !== ']') { if (src[j] === '\\') j++; j++; } continue; }
        if (src[j] === '/' || src[j] === '\n') break;
      }
      continue;
    }
    if (c === '{') depth++;
    else if (c === '}') { depth--; if (depth === 0) return src.slice(start, j + 1); }
  }
  throw new Error('unbalanced: ' + name);
}

const BLADE = 'resources/views/pages/riders-map/partials/fleet.blade.php';
const src = fs.readFileSync(BLADE, 'utf8');

const sandbox = {
  console,
  flEsc: s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  flNum: n => (n === null || n === undefined || isNaN(n)) ? '-' : Number(n).toLocaleString('en-US'),
  flDate: d => d || '',
  flServiceLabel: t => t || '',
  // The single-machine path delegates to this; stub it so we can prove it is
  // used for one machine and BYPASSED for two.
  flvDayChip: () => '[[SINGLE-CHIP]]',
  FL_DAY_TEXT: {},
};
vm.createContext(sandbox);
vm.runInContext(lift(src, 'flClaimRow'), sandbox);

// ---- §1 the claim row -------------------------------------------------------
head('claim rows say which bike the money was for');

const vanClaim = { id: 1, kind: 'fuel', amount: 3000, status: 'approved', source: 'manual',
  meter_at_fill: 74117, vehicle_label: 'CAD-2958', vehicle_stamped: true };
const ownClaim = { id: 2, kind: 'fuel', amount: 57, status: 'approved', source: 'manual',
  meter_at_fill: 6645, vehicle_label: 'APPLIED-FOR', vehicle_stamped: true };

const a = sandbox.flClaimRow(vanClaim, true);
const b = sandbox.flClaimRow(ownClaim, true);
ok('the van claim names CAD-2958', /CAD-2958/.test(a));
ok('the own-bike claim names APPLIED-FOR', /APPLIED-FOR/.test(b));
ok('so the two rows are no longer identical', a !== b && !/APPLIED-FOR/.test(a));

const unstamped = { id: 3, kind: 'fuel', amount: 500, status: 'approved', source: 'manual',
  vehicle_label: null, vehicle_stamped: false };
ok('an unstamped claim says so instead of guessing',
   /machine not recorded/.test(sandbox.flClaimRow(unstamped, true)));
ok('and never borrows a plate', !/CAD-2958|APPLIED-FOR/.test(sandbox.flClaimRow(unstamped, true)));
ok('a one-bike rider gets NO plate (no repeated noise)',
   !/CAD-2958/.test(sandbox.flClaimRow(vanClaim, false)));
ok('and no "not recorded" chip either',
   !/machine not recorded/.test(sandbox.flClaimRow(unstamped, false)));

// ---- §2 the day heading -----------------------------------------------------
head('the day heading lists every bike he held');

// Rebuild just the day-row branch of flRenderDetail against a real two-bike day.
const detail = lift(src, 'flRenderDetail');
const inner = detail.slice(detail.indexOf('const days = (r.days || []).map(d => {'));
const body = inner.slice(inner.indexOf('{'), inner.indexOf('}).join(\'\');'));
vm.runInContext('function renderDay(r, d) ' + body + '}', sandbox);

const TWO = {
  date: '2026-08-29', work_km: 361, status: 'ok', handover: false, claims: [vanClaim, ownClaim],
  meter_start: 17198, meter_end: 17559,
  machines_today: [
    { vehicle_id: 3, label: 'DCR-799', is_company: true, meter_start: 27751, meter_end: null,
      work_km: null, start_at: '10:40', end_at: null, start_source: null, partial: true },
    { vehicle_id: 10, label: 'CEN-455', is_company: true, meter_start: 17198, meter_end: 17559,
      work_km: 361, start_at: '19:30', end_at: '21:37', start_source: 'manager', partial: false },
  ],
};
const rTwo = { user_id: 84, machines: [{}, {}] };
const html = sandbox.renderDay(rTwo, TWO);

ok('both plates appear in the heading', /DCR-799/.test(html) && /CEN-455/.test(html));
ok('the earlier bike is marked handed back', /DCR-799[\s\S]*?handed back/.test(html));
ok('and the last one is not', !/CEN-455[\s\S]*?handed back/.test(html));
ok('they read in the order he rode them', html.indexOf('DCR-799') < html.indexOf('CEN-455'));
ok('the single-machine chip is bypassed', !/SINGLE-CHIP/.test(html));
ok('each bike shows its OWN reading',
   /DCR-799[\s\S]*?27,751/.test(html) && /CEN-455[\s\S]*?17,198/.test(html));
ok('the heading no longer prints one machine pair inline',
   !/17,198 &rarr; 17,559<\/b> · <b>361/.test(html) && !/17,198 → 17,559<\/b> · <b>361/.test(html));
ok('the day total still shows', /361 km/.test(html));
ok('the times are shown so the sequence is legible', /10:40/.test(html) && /19:30/.test(html));

// A machine present only because a claim names it must say so.
const CLAIMONLY = JSON.parse(JSON.stringify(TWO));
CLAIMONLY.machines_today[0] = { vehicle_id: 4, label: 'CAD-2958', is_company: true,
  meter_start: null, meter_end: null, work_km: null, start_at: null, end_at: null,
  start_source: null, partial: false, from_claim: true };
const html2 = sandbox.renderDay(rTwo, CLAIMONLY);
ok('a claim-only machine is labelled as such', /listed because a claim names it/.test(html2));

// ---- §3 a single-machine day is untouched -----------------------------------
head('an ordinary one-bike day is unchanged');
const ONE = { date: '2026-08-28', work_km: 208, status: 'ok', handover: false, claims: [],
  meter_start: 27543, meter_end: 27751,
  machines_today: [{ vehicle_id: 3, label: 'DCR-799', is_company: true, meter_start: 27543,
    meter_end: 27751, work_km: 208, start_at: null, end_at: null, start_source: null, partial: false }] };
const one = sandbox.renderDay({ user_id: 84, machines: [{}] }, ONE);
ok('keeps the original single chip (and its correction link)', /SINGLE-CHIP/.test(one));
ok('still prints the reading pair inline', /27,543/.test(one) && /27,751/.test(one));
ok('adds no per-machine block', !/handed back/.test(one));

console.log('\n' + '-'.repeat(60));
console.log((fail === 0 ? 'ALL GREEN' : 'FAILURES') + ' - passed ' + pass + ', failed ' + fail);
process.exit(fail === 0 ? 0 : 1);
