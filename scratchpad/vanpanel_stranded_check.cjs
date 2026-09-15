/* Drive the REAL vpORow out of the blade's <script> block (function declarations ARE
   reachable in a vm sandbox; top-level let/const are not — see the traps index). */
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync('../resources/views/pages/riders-map/partials/van-panel.blade.php', 'utf8');
const block = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)][0][1];
const js = block.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/\{\{[^}]*\}\}/g, '0')
                .replace(/@[a-zA-Z]+(\([^)]*\))?/g, '');
const sandbox = { document: {addEventListener(){}, getElementById: () => null, querySelector: () => null},
                  window: {}, setInterval(){}, setTimeout(){}, fetch: () => new Promise(()=>{}), console };
sandbox.window = sandbox;
vm.createContext(sandbox);
try { vm.runInContext(js, sandbox); } catch (e) { /* runtime bootstrap may fail; declarations are hoisted */ }

let pass = 0, fail = 0;
const t = (name, ok, d='') => { ok ? pass++ : fail++; console.log((ok?'OK   ':'BAD  ')+name+(d?'  → '+d:'')); };
t('vpORow is reachable', typeof sandbox.vpORow === 'function');

const plain    = sandbox.vpORow('SH-1', 'Ali', 'on the van', 'wait', '', 2, '', false);
const stranded = sandbox.vpORow('SH-2', 'Sara', 'on the van', 'wait', '', 3, '', true);
t('a normal row has no stranded styling', !plain.includes('vp-stranded'));
t('a normal row says nothing about earlier runs', !plain.includes('earlier run'));
t('a stranded row is still RENDERED (never hidden)', stranded.includes('SH-2') && stranded.includes('Sara'));
t('  and carries the stranded class', stranded.includes('vp-stranded'));
t('  and says why out loud', stranded.includes('from an earlier run'));
t('  and keeps its real state chip too', stranded.includes('on the van'));
t('  and keeps its sequence chip', stranded.includes('vp-oseq'));
const undef = sandbox.vpORow('SH-3', 'Zed', 'on the van', 'wait', '', 1, '');
t('an older payload (no stranded key) renders unchanged', !undef.includes('vp-stranded'));
console.log(`\n${pass} passed / ${fail} failed`);
process.exit(fail ? 1 : 0);
