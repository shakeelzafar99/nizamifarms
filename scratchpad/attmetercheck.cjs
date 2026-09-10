/**
 * Drive the REAL `getDistanceBadge` out of pages/attendance/index.blade.php against the
 * rows the REAL `/attendance/data` endpoint returns, so the two-machine marker is proved
 * end to end rather than eyeballed.
 *
 * Usage: node scratchpad/attmetercheck.cjs '<rows json>'
 */
const fs = require('fs');
const vm = require('vm');

const file = 'C:/NF App/nizamifarms/resources/views/pages/attendance/index.blade.php';
const src = fs.readFileSync(file, 'utf8');

// Pull the big inline script and neutralise Blade.
const blocks = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(m => m[1]);
let body = blocks[blocks.length - 1]
  .replace(/\{\{--[\s\S]*?--\}\}/g, '')
  .replace(/\{\{[^}]*\}\}/g, '0')
  .replace(/\{!![^}]*!!\}/g, '0')
  .replace(/@json\([^)]*\)/g, 'null')
  .replace(/^\s*@(if|elseif|else|endif|foreach|endforeach|php|endphp|isset|endisset|can|endcan)\b.*$/gm, '');

const sandbox = {
  console,
  document: { getElementById: () => null, querySelectorAll: () => [], querySelector: () => null,
              addEventListener: () => {}, body: { appendChild: () => {} } },
  window: {}, fetch: () => Promise.resolve({ json: () => ({}) }),
  setTimeout: () => {}, setInterval: () => {}, clearInterval: () => {},
  localStorage: { getItem: () => null, setItem() {} },
  Number, String, Boolean, Array, Object, Math, Date, JSON, parseInt, parseFloat, isNaN,
};
sandbox.window = sandbox; sandbox.globalThis = sandbox;
vm.createContext(sandbox);
try { vm.runInContext(body, sandbox, { filename: 'attendance.blade.php' }); }
catch (e) { console.log('  ✗ the page script threw while loading: ' + e.message); process.exit(1); }

// Stub the two helpers this cell composes with — they are tested by their own surfaces.
sandbox.meterGpsTicks = () => '<TICKS>';
sandbox.getMeterPhotoIcons = () => '<PHOTOS>';

const rows = JSON.parse(process.argv[2]);
let pass = 0, fail = 0;
const ok = (what, cond) => { cond ? (pass++, console.log('  ✓ ' + what)) : (fail++, console.log('  ✗ ' + what)); };

for (const r of rows) {
  const html = sandbox.getDistanceBadge(r);
  const mm = r.meter_machines;
  const who = `u${r.user_id} ${r.fullname || ''}`.trim();

  if (mm && mm.split) {
    console.log(`\n── ${who} — the two-machine day ──`);
    ok('the cell says TWO MACHINES instead of a distance', html.includes('two machines'));
    ok('  …it does NOT print the meaningless difference',
       !new RegExp('>\\s*' + r.meter_distance + ' km').test(html));
    ok('  …it names the machine each reading is of',
       html.includes(mm.start_label) && html.includes(mm.end_label));
    ok('  …and says why the difference is not a distance',
       /not a distance/.test(html));
    ok('  …amber, not red — it is a fact about the day, not a fault',
       html.includes('#B45309'));
  } else if (mm && r.meter_distance != null) {
    console.log(`\n── ${who} — one machine ──`);
    ok('the distance still renders exactly as before',
       new RegExp('>' + r.meter_distance + ' km<').test(html));
    ok('  …with the machine named in the tooltip',
       html.includes(mm.start_label || mm.end_label || '\u0000'));
    ok('  …and no two-machine marker', !html.includes('two machines'));
  }
}

// An older server sends no stamps at all — the cell must be byte-identical to today.
const legacy = { user_id: 0, login_time: '09:00', meter_distance: 42, role_name: 'Rider' };
const before = sandbox.getDistanceBadge(legacy);
console.log('\n── a row with no stamps (older server / pre-Aug-22 history) ──');
ok('renders the plain distance, unchanged', /<span style="font-size:13px[^>]*>42 km<\/span>/.test(before));
ok('  …and carries no tooltip it cannot justify', !before.includes('title='));

console.log('\n' + '─'.repeat(56));
console.log((fail === 0 ? '✅' : '❌') + `  ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
