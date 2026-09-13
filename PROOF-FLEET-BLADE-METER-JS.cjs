/**
 * 🔒 PROOF — the fleet screen's NEW R3 meter JS, driven for real.
 *
 * The blade's top-level `let flvMetersRequired` is SCRIPT-scoped, not a sandbox
 * property, so the probe is APPENDED TO THE SCRIPT BODY rather than run beside it
 * (workspace trap: driving it from outside reads `undefined` and every assertion
 * fails for the wrong reason).
 *
 * Run:  node PROOF-FLEET-BLADE-METER-JS.js
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const FILE = path.join(__dirname, 'resources/views/pages/riders-map/partials/fleet.blade.php');
const src = fs.readFileSync(FILE, 'utf8');

// ── pull every <script> block, drop blade directives the browser never sees.
const blocks = [...src.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]);
let body = blocks.join('\n;\n')
    .replace(/@json\(([^)]*)\)/g, 'null')
    .replace(/\{\{\s*[^}]*\s*\}\}/g, '""')
    .replace(/@[a-zA-Z]+\s*(\([^)]*\))?/g, '');

let pass = 0, fail = 0;
const results = [];

// ── the smallest DOM these functions actually touch.
function makeEl(id) {
    return { id, value: '', textContent: '', innerHTML: '', style: {}, previousElementSibling: null };
}
const els = {};
['flvAssignMeter', 'flvVacatedMeter', 'flvVacatedWrap', 'flvVacatedLabel', 'flvVacatedHint',
 'flvAssignVehicleId', 'flvAssignRider', 'flvAssignError', 'flvAssignSave', 'flvAssignDate',
 'flvAssignNote', 'flvDisplacedBox', 'flvAssignPhotos'].forEach(id => { els[id] = makeEl(id); });
els.flvAssignPhotos.files = [];
const openLabel = makeEl('openLabel');
els.flvAssignMeter.previousElementSibling = openLabel;

const sent = { body: null, url: null };
const sandbox = {
    console,
    document: {
        getElementById: id => els[id] || null,
        querySelector: sel => (sel === 'meta[name="csrf-token"]' ? { getAttribute: () => 'tok' } : null),
        querySelectorAll: () => [],
        createElement: () => makeEl('x'),
        addEventListener: () => {},
        body: { appendChild: () => {} },
    },
    window: {},
    FormData: class { constructor() { this.d = {}; } append(k, v) { this.d[k] = v; } get(k) { return this.d[k]; } },
    fetch: (url, opt) => { sent.url = url; sent.body = opt && opt.body; return Promise.resolve({ json: () => Promise.resolve({}) }); },
    alert: m => { sandbox.__alert = m; },
    confirm: m => { sandbox.__confirm = m; return false; },
    prompt: (...a) => sandbox.__promptFn(...a),
    setTimeout, clearTimeout, setInterval, clearInterval,
    location: { href: '' },
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;

function check(what, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    if (ok) { pass++; results.push('  ✓ ' + what); }
    else { fail++; results.push('  ✗ ' + what + '\n      got:  ' + JSON.stringify(got) + '\n      want: ' + JSON.stringify(want)); }
}
function truthy(what, got) { check(what, !!got, true); }
function falsy(what, got) { check(what, !!got, false); }
sandbox.__check = check; sandbox.__truthy = truthy; sandbox.__falsy = falsy;
sandbox.__els = els; sandbox.__openLabel = openLabel; sandbox.__sent = sent;

const VAC = { field: 'vacated_meter', vehicle_id: 4, label: 'Van CAD-2958', which: 'closing',
              prompt: 'Van CAD-2958 ka meter likhein — company machine hai.' };
const OPEN = { field: 'handover_meter', vehicle_id: 4, label: 'Van CAD-2958', which: 'opening',
               prompt: 'Van CAD-2958 ka meter likhein.' };
sandbox.__VAC = VAC; sandbox.__OPEN = OPEN;

// ── THE PROBE, appended to the script body so it shares the top-level scope.
const probe = `
;(function () {
  var c = __check, t = __truthy, f = __falsy, E = __els;

  // ── 1. nothing required: the box says "optional", the extra box is hidden
  flvRenderMetersRequired([]);
  f('no requirement \\u2192 vacated box hidden', E.flvVacatedWrap.style.display === '');
  t('\\u2026opening box labelled optional', __openLabel.innerHTML.indexOf('optional') > -1);
  c('\\u2026and flvNeedsMeter finds nothing', flvNeedsMeter('handover_meter'), null);

  // ── 2. the server asks for the opening reading only
  flvRenderMetersRequired([__OPEN]);
  t('opening required \\u2192 labelled (required)', __openLabel.innerHTML.indexOf('required') > -1);
  f('\\u2026vacated box STILL hidden', E.flvVacatedWrap.style.display === '');

  // ── 3. the server asks for BOTH \\u2014 the van's close appears, named and prompted
  flvRenderMetersRequired([__OPEN, __VAC]);
  c('both required \\u2192 vacated box shown', E.flvVacatedWrap.style.display, '');
  c('\\u2026named after the VACATED machine', E.flvVacatedLabel.textContent,
    'Van CAD-2958 \\u2014 closing meter');
  c('\\u2026showing the SERVER\\u0027s prompt verbatim', E.flvVacatedHint.textContent, __VAC.prompt);

  // ── 4. going back to "nothing required" clears the typed value (no stale send)
  E.flvVacatedMeter.value = '75484';
  flvRenderMetersRequired([]);
  c('hiding the box clears what was typed', E.flvVacatedMeter.value, '');

  // ── 5. SAVE refuses locally with the server's own words
  E.flvAssignVehicleId.value = '9'; E.flvAssignRider.value = '95';
  E.flvAssignMeter.value = ''; E.flvVacatedMeter.value = '';
  flvRenderMetersRequired([__VAC]);
  __sent.body = null;
  flvSaveAssign();
  c('missing vacated meter \\u2192 refused with the SERVER\\u0027s prompt',
    E.flvAssignError.textContent, __VAC.prompt);
  c('\\u2026and nothing was sent', __sent.body, null);

  // ── 6. …and a non-numeric answer is not "0 km"
  E.flvVacatedMeter.value = 'abc';
  flvSaveAssign();
  c('rubbish in the box is refused too', E.flvAssignError.textContent, __VAC.prompt);
  c('\\u2026still nothing sent', __sent.body, null);

  // ── 7. filled in \\u2192 it goes on the wire under the name the server validates
  E.flvVacatedMeter.value = '75484';
  flvSaveAssign();
  t('filled \\u2192 a request is sent', !!__sent.body);
  c('\\u2026carrying vacated_meter', __sent.body && __sent.body.get('vacated_meter'), 75484);
  c('\\u2026and NOT an empty handover_meter', __sent.body && __sent.body.get('handover_meter'), undefined);

  // ── 8. TAKE BACK asks for the closing reading FIRST, and cancelling changes nothing
  var asked = [];
  __promptFn = function (msg) { asked.push(msg); return null; };
  __sent.body = null;
  flvDoRelease({ id: 4, name: 'Van CAD-2958' }, { user_id: 95, name: 'Rajab Masood' }, [{ field: 'meter', prompt: 'Van ka aakhri meter likhein.' }]);
  c('take-back asks the meter question FIRST', asked[0], 'Van ka aakhri meter likhein.');
  c('\\u2026cancelling asks nothing else', asked.length, 1);
  c('\\u2026and sends nothing', __sent.body, null);

  // ── 9. a non-numeric reading aborts the whole take-back
  __promptFn = function () { return 'dunno'; };
  __alert = null; __sent.body = null;
  flvDoRelease({ id: 4, name: 'Van CAD-2958' }, null, [{ field: 'meter', prompt: 'p' }]);
  t('a non-numeric reading aborts', String(__alert).indexOf('required') > -1);
  c('\\u2026nothing sent', __sent.body, null);

  // ── 10. an OWN bike is never asked
  asked = [];
  __promptFn = function (m) { asked.push(m); return null; };
  flvDoRelease({ id: 9, name: 'Rajab Masood - own bike' }, null, []);
  c('an own bike is never asked for a meter', asked.length, 0);
})();
`;

try {
    vm.createContext(sandbox);
    vm.runInContext(body + probe, sandbox, { timeout: 20000 });
} catch (e) {
    fail++;
    results.push('  ✗ harness threw: ' + e.message);
}

console.log('══ fleet.blade.php — R3 meter JS, driven for real\n');
console.log(results.join('\n'));
console.log('\n───────────────\nPASS ' + pass + '   FAIL ' + fail);
process.exit(fail > 0 ? 1 : 0);
