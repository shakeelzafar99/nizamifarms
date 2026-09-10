/**
 * Drive the REAL claim modal out of riders-map/partials/fleet.blade.php:
 *   • ⛽ New petrol from a COMPANY vehicle card pre-points the picker at THAT machine
 *     and seeds its odometer;
 *   • a claim opened from the fleet HEADER still preselects by the picker's own rule;
 *   • the preference is consumed, so a later reload falls back;
 *   • the card offers petrol on company machines only.
 *
 * ⚠⚠ The page declares its state with top-level `let`, which is SCRIPT-scoped and NOT a
 *    property of the sandbox: reading `sandbox.flNewVehId` sees nothing, and assigning a
 *    helper from outside does not override the real one. So the probe and the stubs are
 *    APPENDED to the script, where that scope is reachable. Getting this wrong makes every
 *    assertion read `undefined` and quietly "fail" for the wrong reason.
 */
const fs = require('fs');
const vm = require('vm');

const file = 'C:/NF App/nizamifarms/resources/views/pages/riders-map/partials/fleet.blade.php';
const src = fs.readFileSync(file, 'utf8');
const blocks = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(m => m[1]);
let body = blocks[blocks.length - 1]
  .replace(/\{\{--[\s\S]*?--\}\}/g, '')
  .replace(/\{\{[^}]*\}\}/g, '0')
  .replace(/\{!![^}]*!!\}/g, '0')
  .replace(/@json\([^)]*\)/g, 'null')
  .replace(/^\s*@(if|elseif|else|endif|foreach|endforeach|php|endphp|isset|endisset|can|endcan)\b.*$/gm, '');

let pass = 0, fail = 0;
const ok = (what, cond) => { cond ? (pass++, console.log('  ✓ ' + what)) : (fail++, console.log('  ✗ ' + what)); };

const nodes = {};
function node(id) {
  if (nodes[id]) return nodes[id];
  return (nodes[id] = {
    id, value: '', textContent: '', innerHTML: '', disabled: false, readOnly: false,
    style: { display: '', background: '' }, dataset: {}, _l: {},
    options: [{ value: '', dataset: {}, textContent: '' }], selectedIndex: 0,
    addEventListener(e, f) { (this._l[e] = this._l[e] || []).push(f); },
    dispatchEvent(ev) { (this._l[ev.type] || []).forEach(f => f.call(this, ev)); return true; },
    querySelectorAll: () => [], focus() {}, getAttribute: () => null, setAttribute() {},
  });
}
let lastCtxUrl = null, ctxResponse = null;

const sandbox = {
  console,
  document: {
    getElementById: id => node(id),
    querySelector: () => node('meta'),
    querySelectorAll: () => [],
    createElement: () => node('tmp'),
    addEventListener() {}, body: node('body'),
  },
  Event: class { constructor(t) { this.type = t; } },
  fetch(url) { lastCtxUrl = url; return Promise.resolve({ json: () => Promise.resolve(ctxResponse) }); },
  alert: msg => { sandbox.__lastAlert = msg; },
  setTimeout: fn => fn(), setInterval() {}, clearInterval() {},
  localStorage: { getItem: () => null, setItem() {} },
  encodeURIComponent, parseInt, parseFloat, JSON, Date, Math,
  String, Number, Boolean, Array, Object, RegExp, Promise, isNaN, setImmediate,
};
sandbox.window = sandbox; sandbox.globalThis = sandbox;

// ⚠ `flExpenseCategoryId` is a CONST filled from the server on render. Relax it to a
//   `let` so the harness can supply the value the page would have been given.
body = body.replace(/const flExpenseCategoryId =/, "let flExpenseCategoryId =");

body += `
;globalThis.__p = {
  get vehId(){ return flNewVehId; },
  get metered(){ return flNewMetered; },
  get prefer(){ return flNewPreferVehId; },
  init(cat, data){
    flExpenseCategoryId = cat;
    flData = data;
    flvData = { vehicles: [] };
    flNewFillPaySources = function(){};
    flFillMaintTypes    = function(){};
    flNewError          = function(){};
    flNewSvcChanged     = function(){};
  },
  openNew: (c) => flOpenNew(c),
  loadVehicles: () => flNewLoadVehicles(),
  newPetrol: (u, v, m) => flvNewPetrol(u, v, m),
  riderChanged: () => flNewRiderChanged(),
};`;

vm.createContext(sandbox);
try { vm.runInContext(body, sandbox, { filename: 'fleet.blade.php' }); }
catch (e) { console.log('  ✗ the partial threw while loading: ' + e.message); process.exit(1); }

// ⚠ The rider select is wired in MARKUP — `onchange="flNewRiderChanged()"` (line 845) —
//   which a real browser fires on dispatchEvent. This fake node only knows listeners, so
//   the markup wiring is mirrored here. Verified against the blade above, not invented.
node("flNewRider").addEventListener("change", () => sandbox.__p.riderChanged());

sandbox.__p.init(7, { riders: [
  { user_id: 84, name: 'Danish Ali', bike: 'company', vehicle_label: 'EGL-682', holds_now: true },
], maint_types: [] });

// The day this rider had TWO machines. His OWN bike carries the claimable kilometres, so the
// picker's own rule would choose it — the vehicle card asks for the company one instead.
ctxResponse = { success: true, petrol_rate: 12, vehicles: [
  { vehicle_id: 10, label: 'EGL-682', is_company: false, vtype: 'bike', km: 41,
    can_meter_claim: true,  suggested_amount: 492, claim: null },
  { vehicle_id: 3,  label: 'DCR-799', is_company: true,  vtype: 'bike', km: null,
    can_meter_claim: false, suggested_amount: null, claim: null },
]};

const flush = () => new Promise(r => setImmediate(r));

(async () => {
  console.log('\n== ⛽ New petrol, opened from the COMPANY machine\'s own card ==');
  sandbox.__p.newPetrol(84, 3, 28243);
  await flush(); await flush();
  ok('the modal opened as a petrol claim', node('flNewTitle').textContent.includes('petrol'));
  ok('  …for the machine\'s keeper', node('flNewRider').value === '84');
  ok('  …asking the server for HIS day', (lastCtxUrl || '').includes('user_id=84'));
  ok('  ⭐ …and the picker lands on the machine he opened, not the picker\'s own guess',
     sandbox.__p.vehId === 3);
  ok('  ⭐ …with the odometer he is looking at seeded', node('flNewMeter').value === '28243');
  ok('  …left EDITABLE — the fill may have been at a lower reading',
     node('flNewMeter').readOnly !== true);
  ok('  ⚠ …and NOT priced as a per-km claim (that is own-bike money)',
     sandbox.__p.metered === false);
  ok('  …so the amount stays the manager\'s to enter', node('flNewAmount').readOnly === false);

  console.log('\n== the preference is CONSUMED, never sticky ==');
  ok('it is cleared once used', sandbox.__p.prefer === null);
  node('flNewRider').value = '84';
  sandbox.__p.loadVehicles();
  await flush(); await flush();
  ok('  ⭐ a later reload falls back to the picker\'s own rule (his own bike)',
     sandbox.__p.vehId === 10);

  console.log('\n== a claim opened from the fleet HEADER is untouched ==');
  sandbox.__p.openNew('Petrol');
  ok('no machine is preferred', sandbox.__p.prefer === null);
  node('flNewRider').value = '84';
  sandbox.__p.loadVehicles();
  await flush(); await flush();
  ok('  …the picker still chooses the claimable own machine', sandbox.__p.vehId === 10);
  ok('  …and prices it per km', sandbox.__p.metered === true);

  console.log('\n== a machine with nobody holding it cannot be claimed against ==');
  sandbox.__lastAlert = null;
  sandbox.__p.newPetrol(0, 3, 100);
  ok('it refuses, and says why', /no keeper/i.test(sandbox.__lastAlert || ''));

  console.log('\n== the card offers petrol on COMPANY machines only ==');
  ok('the petrol button is behind v.is_company', /v\.is_company\)[\s\S]{0,240}flvNewPetrol/.test(src));
  ok('  …and New maintenance is offered either way',
     (src.match(/flvNewMaintenance\(/g) || []).length >= 3);

  console.log('\n' + '─'.repeat(56));
  console.log((fail === 0 ? '✅' : '❌') + `  ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
