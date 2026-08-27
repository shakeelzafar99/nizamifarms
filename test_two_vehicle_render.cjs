/**
 * RENDERER HARNESS for the two-vehicle fuel UI (Aug-27 2026).
 *
 * A green PHP suite says nothing about the JavaScript that turns a payload into a
 * screen — and on this codebase the browser has repeatedly found what unit checks
 * could not. These lift the real functions out of the blades (brace-matching, same
 * technique as test_fleet_render.cjs) and run them against realistic payloads.
 *
 * What it proves:
 *   • the vehicle picker renders a chip per machine, marks claimed ones, and
 *     preselects the own bike with unclaimed kilometres;
 *   • picking an own bike switches the form to the PER-KM claim (amount priced and
 *     locked, meter box hidden) and picking the van does not;
 *   • an EDIT never becomes a per-km claim;
 *   • the meter editor prefills the day's driver but never overrides a recorded one.
 *
 * Run:  node test_two_vehicle_render.cjs
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
function ok(what, got, want) {
    const good = JSON.stringify(got) === JSON.stringify(want);
    if (good) { pass++; console.log('  ✓ ' + what); }
    else { fail++; console.log('  ✗ ' + what);
           console.log('      got:  ' + JSON.stringify(got));
           console.log('      want: ' + JSON.stringify(want)); }
}
function head(t) { console.log('\n== ' + t + ' =='); }

/**
 * Pull `function NAME(...) { ... }` out of a blade by matching braces.
 *
 * ⚠ COMMENT-AWARE ON PURPOSE. The older harness scans only for quotes, so a single
 *   apostrophe inside a `//` comment ("the rider's month-level label") reads as an
 *   opening string, brace counting drifts, and the lift silently runs past the end of
 *   the function into unrelated code. It fails loudly here only by luck. Skipping
 *   comments and regex literals costs a few lines and removes a whole class of
 *   phantom failure — the alternative is writing worse comments to appease a scanner.
 */
function lift(src, name) {
    const start = src.indexOf('function ' + name + '(');
    if (start === -1) throw new Error('function ' + name + ' not found');
    const open = src.indexOf('{', start);
    let depth = 0;
    // What can precede a `/` that begins a REGEX rather than a division.
    const REGEX_OK = /[(,=:[!&|?{};+\-*%~^]\s*$/;

    for (let j = open; j < src.length; j++) {
        const c = src[j], next = src[j + 1];

        if (c === '/' && next === '/') {                       // line comment
            j = src.indexOf('\n', j); if (j === -1) break; continue;
        }
        if (c === '/' && next === '*') {                       // block comment
            j = src.indexOf('*/', j + 2); if (j === -1) break; j++; continue;
        }
        if (c === '"' || c === "'" || c === '`') {             // string / template
            const quote = c;
            for (j++; j < src.length; j++) {
                if (src[j] === '\\') { j++; continue; }
                if (src[j] === quote) break;
            }
            continue;
        }
        if (c === '/' && REGEX_OK.test(src.slice(Math.max(0, j - 40), j))) {
            for (j++; j < src.length; j++) {                   // regex literal
                if (src[j] === '\\') { j++; continue; }
                if (src[j] === '[') { while (j < src.length && src[j] !== ']') { if (src[j] === '\\') j++; j++; } continue; }
                if (src[j] === '/') break;
                if (src[j] === '\n') break;                    // not a regex after all
            }
            continue;
        }
        if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) return src.slice(start, j + 1); }
    }
    throw new Error('unbalanced braces lifting ' + name);
}

// ── a minimal DOM: only what these functions actually touch ──────────────────
function makeDom(ids) {
    const nodes = {};
    ids.forEach(id => {
        nodes[id] = {
            id, value: '', textContent: '', innerHTML: '', disabled: false,
            readOnly: false, style: {}, dataset: {}, options: [], selectedIndex: -1,
            querySelectorAll: () => [],
        };
    });
    return {
        getElementById: id => nodes[id] || null,
        querySelectorAll: () => [],
        _nodes: nodes,
    };
}

// =============================================================================
head('the New-petrol vehicle picker');

const fleetSrc = fs.readFileSync('resources/views/pages/riders-map/partials/fleet.blade.php', 'utf8');

const IDS = ['flNewVehWrap', 'flNewVehChips', 'flNewVehNote', 'flNewAmount', 'flNewMeterWrap',
             'flNewRider', 'flNewMeterReq', 'flNewMeterHint', 'flNewSvcDue', 'flNewServiceType'];
const doc = makeDom(IDS);
doc._nodes.flNewRider.options = [{ dataset: { company: '0' } }];
doc._nodes.flNewRider.selectedIndex = 0;
doc._nodes.flNewServiceType.selectedOptions = [{ dataset: {} }];

const sandbox = {
    document: doc,
    console,
    flEsc: s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
    flNum: n => (n === null || n === undefined || isNaN(n)) ? '—' : Number(n).toLocaleString('en-US'),
    flSvcBucket: () => '',
    flNewCat: 'Petrol',
    flNewCtx: null, flNewVehId: null, flNewMetered: false, flEditId: null,
};
vm.createContext(sandbox);
vm.runInContext(lift(fleetSrc, 'flNewApplyVehicle'), sandbox);
vm.runInContext(lift(fleetSrc, 'flNewPickVehicle'), sandbox);
vm.runInContext(lift(fleetSrc, 'flNewSvcChanged'), sandbox);

// A real mixed day: own bike with 40 unclaimed km, plus the van.
const MIXED = {
    attendance_id: 2905, petrol_rate: 9.5,
    vehicles: [
        { vehicle_id: 9, label: 'APPLIED-FOR', is_company: false, km: 40, meter_start: 6800,
          meter_end: 6840, source: 'log', entered_by_name: 'Shabib',
          can_meter_claim: true, suggested_amount: 380, claim: null },
        { vehicle_id: 4, label: 'Van', is_company: true, km: 116, meter_start: 74284,
          meter_end: 74400, source: 'attendance', entered_by_name: null,
          can_meter_claim: false, suggested_amount: null, claim: null },
    ],
};

sandbox.flNewCtx = MIXED;
vm.runInContext('flNewPickVehicle(9)', sandbox);
ok('own bike selected → per-km mode', sandbox.flNewMetered, true);
ok('  …amount priced from the kilometres', doc._nodes.flNewAmount.value, 380);
ok('  …and locked (a typed figure would be different money)', doc._nodes.flNewAmount.readOnly, true);
ok('  …the meter box is hidden', doc._nodes.flNewMeterWrap.style.display, 'none');
const note1 = doc._nodes.flNewVehNote.innerHTML;
ok('  …the note shows the sum', /40 km.*9\.5.*380/.test(note1.replace(/<[^>]+>/g, '')), true);
ok('  …and credits who entered the readings', /Shabib/.test(note1), true);
ok('  …meter no longer demanded for an own-bike claim', doc._nodes.flNewMeterReq.textContent, '(optional)');

vm.runInContext('flNewPickVehicle(4)', sandbox);
ok('van selected → flat cash, never per-km', sandbox.flNewMetered, false);
ok('  …amount unlocked again', doc._nodes.flNewAmount.readOnly, false);
ok('  …meter box back', doc._nodes.flNewMeterWrap.style.display, '');
ok('  …and the meter is REQUIRED on the company vehicle', doc._nodes.flNewMeterReq.textContent, '(required)');
ok('  …note explains the firm buys its fuel',
   /firm buys the fuel/.test(doc._nodes.flNewVehNote.innerHTML), true);

// already claimed
const CLAIMED = JSON.parse(JSON.stringify(MIXED));
CLAIMED.vehicles[0].can_meter_claim = false;
CLAIMED.vehicles[0].claim = { id: 2795, status: 'approved', amount: 380, number: 'REQ-0262', metered: true };
sandbox.flNewCtx = CLAIMED;
vm.runInContext('flNewPickVehicle(9)', sandbox);
ok('an already-claimed machine cannot be filed again', sandbox.flNewMetered, false);
ok('  …and says so, with the request number',
   /Already claimed.*REQ-0262/.test(doc._nodes.flNewVehNote.innerHTML), true);

// EDIT mode must never re-price a claim
sandbox.flNewCtx = MIXED;
sandbox.flEditId = 2795;
vm.runInContext('flNewPickVehicle(9)', sandbox);
ok('EDITING never switches a claim to per-km', sandbox.flNewMetered, false);
ok('  …and never rewrites its amount', doc._nodes.flNewAmount.readOnly, false);
sandbox.flEditId = null;

// a rider with no rate
const NORATE = JSON.parse(JSON.stringify(MIXED));
NORATE.petrol_rate = null;
NORATE.vehicles[0].can_meter_claim = false;
NORATE.vehicles[0].suggested_amount = null;
sandbox.flNewCtx = NORATE;
vm.runInContext('flNewPickVehicle(9)', sandbox);
ok('no per-km rate → an ordinary cash claim', sandbox.flNewMetered, false);
ok('  …explained rather than silently different',
   /No per-kilometre rate/.test(doc._nodes.flNewVehNote.innerHTML), true);

// =============================================================================
head('the vehicle meter editor — driver prefill');

const doc2 = makeDom([]);
const sandbox2 = {
    document: doc2, console,
    flEsc: sandbox.flEsc, flNum: sandbox.flNum,
    flvMeterWindowHint: () => '',
};
vm.createContext(sandbox2);
vm.runInContext(lift(fleetSrc, 'flvMeterForm'), sandbox2);

const DRIVERS = [{ user_id: 95, name: 'Rajab Masood' }, { user_id: 79, name: 'Shabib' }];

// (a) nothing recorded yet → the day's holder is offered
let html = sandbox2.flvMeterForm({
    attendance: null, log: null, can_edit_attendance: true,
    drivers: DRIVERS, suggested_driver: { user_id: 95, name: 'Rajab Masood' }, window: {},
});
ok('the assigned driver is preselected', /<option value="95" selected>/.test(html), true);
ok('  …and the reason is stated', /Assigned that day/.test(html), true);
ok('  …"no driver" is still available', /— no driver \(machine only\) —/.test(html), true);

// (b) a row that already names someone must WIN over the suggestion
html = sandbox2.flvMeterForm({
    attendance: null,
    log: { id: 3, meter_start: 73562, meter_end: 73688, driver_user_id: 79, note: '' },
    can_edit_attendance: true,
    drivers: DRIVERS, suggested_driver: { user_id: 95, name: 'Rajab Masood' }, window: {},
});
ok('a recorded driver outranks the suggestion', /<option value="79" selected>/.test(html), true);
ok('  …the suggestion is not also selected', /<option value="95" selected>/.test(html), false);
ok('  …and the hint is not shown over a recorded fact', /Assigned that day/.test(html), false);

// (c) no suggestion at all → exactly the old behaviour
html = sandbox2.flvMeterForm({
    attendance: null, log: null, can_edit_attendance: true,
    drivers: DRIVERS, suggested_driver: null, window: {},
});
ok('no suggestion → nothing preselected, as before', /selected/.test(html), false);

console.log('\n' + '─'.repeat(60));
console.log((fail === 0 ? 'ALL GREEN' : 'FAILURES') + ' — passed ' + pass + ', failed ' + fail);
process.exit(fail === 0 ? 0 : 1);
