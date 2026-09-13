/**
 * Drive the WEB blades' own JavaScript, without a login.
 *
 * ⚠⚠ THE TRAP THIS AVOIDS (from the Sep-10 round): a Blade page's top-level `let`/`const` is
 *    SCRIPT-scoped, not a property of the vm sandbox — reading `sandbox.flSelected` gives
 *    `undefined` and every assertion fails for the wrong reason. So a probe is APPENDED to the
 *    script body instead, and reads the real bindings from inside that scope.
 *
 * What it proves: the new functions exist, are syntactically valid in a browser context, and
 * emit the right requests/markup. It does NOT replace a click-through — it is what can be
 * checked without the owner's session.
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
const ok = (what, got, want) => {
  const good = JSON.stringify(got) === JSON.stringify(want);
  if (good) { pass++; console.log('  \u2713 ' + what); }
  else { fail++; console.log('  \u2717 ' + what); console.log('      got:  ' + JSON.stringify(got)); console.log('      want: ' + JSON.stringify(want)); }
};
const section = t => console.log('\n== ' + t + ' ==');

/** Pull the <script> bodies out of a blade and strip Blade directives that are not JS. */
function scriptsOf(file) {
  const src = fs.readFileSync(file, 'utf8');
  const out = [];
  const re = /<script[^>]*>([\s\S]*?)<\/script>/g;
  let m;
  while ((m = re.exec(src))) out.push(m[1]);
  return out.join('\n')
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')          // blade comments
    .replace(/@json\(([^)]*)\)/g, 'null')
    .replace(/\{\{\s*[^}]*\s*\}\}/g, 'null')       // {{ $x }}
    .replace(/@[a-zA-Z]+(\([^)]*\))?/g, '');       // @if/@endif etc
}

// ── a minimal DOM good enough for these functions ─────────────────────
function makeEnv(calls) {
  const el = (id) => ({
    id, value: '', innerHTML: '', textContent: '', style: {}, dataset: {}, files: [],
    disabled: false, checked: false, appendChild() {}, remove() {},
    querySelector: () => null, querySelectorAll: () => [],
    getAttribute: () => 'csrf-token-stub', classList: { add(){}, remove(){}, contains(){return false;} },
  });
  const store = {};
  const document = {
    getElementById: id => (store[id] = store[id] || el(id)),
    querySelector: () => el('meta'),
    querySelectorAll: () => [],
    createElement: () => el('div'),
    addEventListener() {},
    body: el('body'),
  };
  const fetchCalls = calls;
  const fetch = (url, opts) => {
    fetchCalls.push({ url, method: (opts && opts.method) || 'GET', body: opts && opts.body });
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, types: [], pay_sources: [], message: 'ok' }) });
  };
  return { document, fetch, window: { location: { href: '' } }, console,
           setTimeout, clearTimeout, setInterval, clearInterval,
           alert: msg => fetchCalls.push({ alert: String(msg) }),
           confirm: () => true, localStorage: { getItem: () => null, setItem() {} },
           FormData: class { constructor(){ this._d = []; } append(k, v){ this._d.push([k, v]); }
                             has(k){ return this._d.some(p => p[0] === k); } },
           Promise, JSON, Math, Date, String, Number, Array, Object, Boolean, isNaN, parseInt, parseFloat };
}

// ─────────────────────────────────────────────────
section('§1 fleet.blade — the new workshop functions load and behave');

const calls = [];
const env = makeEnv(calls);
const ctx = vm.createContext(env);
let body = scriptsOf('C:/NF App/nizamifarms/resources/views/pages/riders-map/partials/fleet.blade.php');

// ⭐ APPEND the probe INSIDE the script body — see the trap note at the top.
body += `
;globalThis.__probe = {
  has: n => typeof globalThis[n] === 'function' || typeof eval('typeof ' + n) === 'function',
  call: (n, ...a) => eval(n).apply(null, a),
};`;

let loaded = true, loadErr = null;
try { vm.runInContext(body, ctx, { timeout: 15000 }); }
catch (e) { loaded = false; loadErr = e.message; }

ok('the blade\u2019s script body parses and runs', loaded, true);
if (!loaded) console.log('      ' + loadErr);

if (loaded) {
  const has = n => { try { return ctx.__probe.has(n); } catch (e) { return false; } };
  ok('flWorkshopDone exists (the rebuilt close dialog)', has('flWorkshopDone'), true);
  ok('flWsDoneSave exists (its multipart submit)', has('flWsDoneSave'), true);
  ok('flWorkshopSnooze exists (Later 6h)', has('flWorkshopSnooze'), true);
  ok('flWorkshopDepart exists ("He has gone")', has('flWorkshopDepart'), true);
  ok('flWorkshopArrivedHere exists ("He is there")', has('flWorkshopArrivedHere'), true);

  // Opening the close dialog must ask the SERVER for this visit's job list.
  calls.length = 0;
  try { ctx.__probe.call('flWorkshopDone', 99); } catch (e) { /* DOM stub gaps are fine */ }
  const typeCall = calls.find(c => c.url && String(c.url).includes('/workshop/99/types'));
  ok('opening it fetches the per-visit job list', !!typeCall, true);

  // The snooze must POST, not GET.
  calls.length = 0;
  try { ctx.__probe.call('flWorkshopSnooze', 77); } catch (e) {}
  const sn = calls.find(c => c.url && String(c.url).includes('/77/snooze'));
  ok('Later posts to the snooze route', !!sn && sn.method === 'POST', true);

  // "He is there" must POST to arrived-here.
  calls.length = 0;
  try { ctx.__probe.call('flWorkshopArrivedHere', 88); } catch (e) {}
  const ah = calls.find(c => c.url && String(c.url).includes('/88/arrived-here'));
  ok('"He is there" posts to arrived-here', !!ah && ah.method === 'POST', true);
}

// ─────────────────────────────────────────────────
section('§2 orders blade — the assign warning is wired on both paths');

const ordersSrc = fs.readFileSync('C:/NF App/nizamifarms/resources/views/pages/orders/index.blade.php', 'utf8');
ok('the detail-modal path reads result.warning',
   ordersSrc.includes("result.warning && result.warning.kind === 'workshop'"), true);
ok('the quick-assign path reads aJson.warning',
   ordersSrc.includes("aJson.warning && aJson.warning.kind === 'workshop'"), true);
/* \u26a0 Match the EMITTED string, not the word \u2014 the comment explaining the change also contains
     the retired phrase, and matching that made the check fail on its own documentation. */
ok('\u26a0 the retired "do not assign him orders" line is no longer EMITTED',
   ordersSrc.includes("+ ' \u2014 do not assign him orders.'"), false);
ok('\u2026replaced by the new instruction',
   ordersSrc.includes('assign freely; dispatch when he is back'), true);
ok('the live card still guards the sparse dispatch map (this morning\u2019s fix)',
   ordersSrc.includes('var wsTrip = (disp || {}).workshop_trip'), true);

console.log('\n\u2500'.repeat(60));
console.log(fail === 0 ? `ALL GREEN \u2014 passed ${pass}, failed 0` : `passed ${pass}, FAILED ${fail}`);
process.exit(fail === 0 ? 0 : 1);
