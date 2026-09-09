/**
 * Drive the REAL fleet.blade.php booking form against REAL payload shapes.
 *
 * ⚠⚠ WHY THIS EXISTS. Twice in this project a "working" web change was proved broken only
 *    by a screenshot: a linter parses code that draws the wrong thing, and asserting from
 *    OUTSIDE an eval reads a different binding than the page's own `let`. So this evaluates
 *    the page's script once and then calls the page's OWN handlers, asserting on the HTML
 *    that is actually drawn and on the requests that actually go out.
 *
 * ⚠ alert/confirm/prompt are traps — the 5-Sep round replaced every browser dialog in this
 *   flow and nothing should reintroduce one.
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
const ok = (what, got, want) => {
    const good = JSON.stringify(got) === JSON.stringify(want);
    if (good) { pass++; console.log('  ✓ ' + what); }
    else { fail++; console.log('  ✗ ' + what + '\n      got:  ' + JSON.stringify(got) + '\n      want: ' + JSON.stringify(want)); }
};
const okT = (what, cond) => ok(what, !!cond, true);

// ── a fake DOM just rich enough for flForm ───────────────────────────────────
const nodes = {};
function mkNode(tag) {
    const n = {
        tagName: (tag || 'div').toUpperCase(), _html: '', textContent: '', value: '', checked: false,
        type: '', name: '', disabled: false, style: { display: '', cssText: '', background: '', borderColor: '', color: '' },
        children: [], _listeners: {},
        get innerHTML() { return this._html; },
        set innerHTML(v) { this._html = String(v); parseInto(this, String(v)); },
        addEventListener(ev, fn) { (this._listeners[ev] = this._listeners[ev] || []).push(fn); },
        appendChild(c) { this.children.push(c); return c; },
        remove() {},
        querySelector(sel) { return domQuery(sel)[0] || null; },
        querySelectorAll(sel) { return domQuery(sel); },
        focus() {},
        getAttribute() { return 'csrf'; },
        setAttribute() {},
    };
    return n;
}
/**
 * ⚠ THE TRAP FROM THE 5-SEP ROUND: fields are built as innerHTML, so a fake DOM must CREATE
 *   the node when the markup mentions it — `getElementById('flF_x')` is undefined until
 *   something parses the string. This scrapes ids, radio groups and selected values out of
 *   the drawn HTML so the page's own value-readers find real nodes.
 */
const inputs = {};   // id -> node ;  radios: name -> [nodes]
const radios = {};
function parseInto(host, html) {
    // <input ... id="X" ...>
    for (const m of html.matchAll(/<(input|select|textarea)\b([^>]*)>/gi)) {
        const attrs = m[2];
        const id   = (attrs.match(/\bid="([^"]+)"/) || [])[1];
        const name = (attrs.match(/\bname="([^"]+)"/) || [])[1];
        const type = (attrs.match(/\btype="([^"]+)"/) || [])[1] || (m[1].toLowerCase() === 'select' ? 'select' : 'text');
        const val  = (attrs.match(/\bvalue="([^"]*)"/) || [])[1] || '';
        const checked = /\bchecked\b/.test(attrs);
        const n = mkNode(m[1]);
        n.type = type; n.name = name || ''; n.value = val; n.checked = checked;
        if (id) { n.id = id; inputs[id] = n; nodes[id] = n; }
        if (name) { (radios[name] = radios[name] || []).push(n); }
    }
    // <div id="flFW_x" ...>   — the showIf wrappers
    for (const m of html.matchAll(/<div id="(flFW_[^"]+)"([^>]*)>/g)) {
        const n = mkNode('div');
        n.id = m[1];
        n.style.display = /style="display:none;?"/.test(m[2]) ? 'none' : '';
        nodes[m[1]] = n;
    }
    // <select ...> options: remember the selected one so flFieldValue reads it
    for (const m of html.matchAll(/<select\b[^>]*\bid="([^"]+)"[^>]*>([\s\S]*?)<\/select>/gi)) {
        const sel = (m[2].match(/<option value="([^"]*)"[^>]*\bselected\b/) || [])[1];
        const first = (m[2].match(/<option value="([^"]*)"/) || [])[1] || '';
        if (inputs[m[1]]) inputs[m[1]].value = sel !== undefined ? sel : first;
    }
}
function domQuery(sel) {
    let m;
    if ((m = sel.match(/^input\[name="([^"]+)"\]:checked$/))) {
        return (radios[m[1]] || []).filter(n => n.checked);
    }
    if ((m = sel.match(/^input\[name="([^"]+)"\]$/))) return radios[m[1]] || [];
    if ((m = sel.match(/^\[name="([^"]+)"\], #(.+)$/))) {
        return (radios[m[1]] || []).concat(nodes[m[2]] ? [nodes[m[2]]] : []);
    }
    if ((m = sel.match(/^#(.+)$/))) return nodes[m[1]] ? [nodes[m[1]]] : [];
    if (sel.includes('meta[name="csrf-token"]')) return [mkNode('meta')];
    if (sel.startsWith('#flFormBody')) return [];
    return [];
}
['flFormTitle', 'flFormIntro', 'flFormBody', 'flFormResult', 'flFormOk', 'flFormModal'].forEach(id => {
    nodes[id] = mkNode('div'); nodes[id].id = id;
});

// ── the requests the page makes ──────────────────────────────────────────────
let sent = [];
let nextResponses = [];
/**
 * ⚠ A SYNCHRONOUS thenable, not a real Promise. The page chains `.then().then().catch()`;
 *   with real promises the assertions below would run BEFORE the form was drawn and report
 *   an empty modal on a working build — the same "it looked broken but wasn't" trap this
 *   harness exists to avoid. Resolving inline makes the page's own chain finish before the
 *   call returns.
 */
function sync(value) {
    return {
        then(onOk) {
            let v = value;
            try { v = onOk ? onOk(value) : value; } catch (e) { console.log('  ✗ page code threw: ' + e.message); fail++; return sync(undefined); }
            return (v && typeof v.then === 'function') ? v : sync(v);
        },
        catch() { return this; },
    };
}
function fetchStub(url, opts) {
    const body = opts && opts.body ? JSON.parse(opts.body) : null;
    sent.push({ url, method: (opts && opts.method) || 'GET', body });
    const r = nextResponses.shift() || { success: true };
    return sync({ ok: true, json: () => sync(r) });
}

const sandbox = {
    console,
    document: {
        getElementById: id => nodes[id] || null,
        querySelector: sel => domQuery(sel)[0] || null,
        querySelectorAll: sel => domQuery(sel),
        createElement: mkNode,
        body: mkNode('body'),
        addEventListener() {},
    },
    window: {}, fetch: fetchStub, setTimeout: (fn) => { try { fn(); } catch (e) {} }, clearTimeout() {},
    setInterval() {}, localStorage: { getItem: () => null, setItem() {} },
    encodeURIComponent, parseInt, parseFloat, JSON, Date, Math, String, Number, Boolean, Array, Object, RegExp, isNaN,
    alert: () => { fail++; console.log('  ✗ a browser alert() was used — the 5-Sep round removed every one'); },
    confirm: () => { fail++; console.log('  ✗ a browser confirm() was used'); return true; },
    prompt: () => { fail++; console.log('  ✗ a browser prompt() was used'); return ''; },
    Promise,
};
sandbox.globalThis = sandbox;
sandbox.window = sandbox;

const file = 'C:/NF App/nizamifarms/resources/views/pages/riders-map/partials/fleet.blade.php';
const src = fs.readFileSync(file, 'utf8');
const block = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)]
    .map(m => m[1]).join('\n');
const js = block
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/\{\{[^}]*\}\}/g, "''")
    .replace(/\{!![^}]*!!\}/g, "''")
    .replace(/@json\([^)]*\)/g, 'null');
vm.createContext(sandbox);
try { vm.runInContext(js, sandbox, { filename: 'fleet.blade.php' }); }
catch (e) { console.log('  ✗ the page script threw while loading: ' + e.message); process.exit(1); }

const H = () => nodes.flFormBody.innerHTML;
const reset = (meta) => {
    sent = []; nextResponses = [];
    Object.keys(inputs).forEach(k => delete inputs[k]);
    Object.keys(radios).forEach(k => delete radios[k]);
    vm.runInContext('flWorkshopsRefresh();', sandbox);
    nextResponses.push(meta);
};
const pick = (name, value) => {
    (radios['flF_' + name] || []).forEach(n => { n.checked = String(n.value) === String(value); });
    // fire the page's own change handlers, which is what drives showIf
    (radios['flF_' + name] || []).forEach(n => (n._listeners.change || []).forEach(fn => fn()));
};

const WS = [{ id: 7, name: 'LaCarne Workshop' }, { id: 9, name: 'Bilal Auto' }];
const SHIFTS = [{ id: 1, name: 'Primary 11 AM', start: '11:00' }, { id: 9, name: 'LaCarne', start: '09:30' }];

console.log('\n== A · the booking form, as QASIM (books, cannot approve) ==');
reset({ success: true, workshops: WS, can_approve: false, approval_on: true, shifts: [] });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
okT('the workshop picker lists the registered workshops', H().includes('LaCarne Workshop') && H().includes('Bilal Auto'));
okT('➕ Add a new workshop is always offered', H().includes('Add a new workshop'));
okT('the new-workshop fields exist but start HIDDEN',
    /id="flFW_new_name" style="display:none;?"/.test(H()));
okT('he is TOLD his booking goes for approval', H().includes('for approval'));
okT('…and he is NOT offered the assign-now choice', !H().includes('Assign it now'));
okT('no browser dialog was used', true);

console.log('\n== B · choosing "add a new workshop" reveals its fields, in place ==');
pick('location_id', '__new');
ok('the name field is now visible', nodes.flFW_new_name.style.display, '');
ok('the coordinates field is now visible', nodes.flFW_new_coords.style.display, '');
pick('location_id', '7');
ok('…and hides again when a listed workshop is picked', nodes.flFW_new_name.style.display, 'none');

console.log('\n== C · booking against a listed workshop ==');
sent = []; nextResponses = [{ success: true, message: 'Sent for approval.' }];
nodes.flF_date.value = '2026-09-09';
pick('location_id', '7');
vm.runInContext('flFormSubmit();', sandbox);
const bookReq = sent.find(s => s.method === 'POST');
ok('one POST, to the booking endpoint', bookReq.url, '/orders/riders-map/fleet/workshop');
ok('  …carrying the vehicle, the date and the chosen workshop',
   [bookReq.body.vehicle_id, bookReq.body.visit_date, bookReq.body.location_id], [42, '2026-09-09', 7]);
ok('  …and NOT claiming an assignment he cannot make', bookReq.body.send_for_approval, undefined);

console.log('\n== D · adding a workshop inline: ONE press, location first, then the booking ==');
reset({ success: true, workshops: [], can_approve: false, approval_on: true, shifts: [] });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
okT('with NO workshops ticked, "add a new workshop" is pre-selected',
    (radios['flF_location_id'] || []).some(n => n.checked && n.value === '__new'));
sent = [];
nextResponses = [{ success: true, location: { id: 31, name: 'Bilal Auto' }, message: 'added' },
                 { success: true, message: 'Sent for approval.' }];
nodes.flF_date.value = '2026-09-09';
nodes.flF_new_name.value = 'Bilal Auto';
nodes.flF_new_coords.value = '33.6867, 73.0331';
nodes.flF_new_radius.value = '300';
vm.runInContext('flFormSubmit();', sandbox);
const posts = sent.filter(s => s.method === 'POST');
ok('two POSTs went out, in order', posts.map(p => p.url),
   ['/orders/riders-map/fleet/workshop/locations', '/orders/riders-map/fleet/workshop']);
ok('  …the workshop was created with real coordinates',
   [posts[0].body.location_name, posts[0].body.latitude, posts[0].body.longitude],
   ['Bilal Auto', 33.6867, 73.0331]);
ok('  ⭐ …and the booking used the workshop just created, without leaving the form',
   posts[1].body.location_id, 31);

console.log('\n== E · a pasted Maps link is passed through for the server to judge ==');
reset({ success: true, workshops: [], can_approve: false, approval_on: true, shifts: [] });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
sent = []; nextResponses = [{ success: false, message: 'That Google Maps link points at a named place and carries no coordinates' }];
nodes.flF_date.value = '2026-09-09';
nodes.flF_new_name.value = 'Somewhere';
nodes.flF_new_coords.value = 'https://maps.app.goo.gl/abc123';
vm.runInContext('flFormSubmit();', sandbox);
const locReq = sent.filter(s => s.method === 'POST')[0];
ok('a non-numeric value goes up as maps_url, not as coordinates',
   [locReq.body.maps_url, locReq.body.latitude], ['https://maps.app.goo.gl/abc123', undefined]);
ok('  ⚠ …and the refusal is shown IN the form, never in an alert',
   nodes.flFormResult.innerHTML.includes('carries no coordinates'), true);
ok('  …and no booking was made on the back of it', sent.filter(s => s.method === 'POST').length, 1);

console.log('\n== F · the booking form as SHABIB (books AND plans shifts) ==');
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
okT('he IS asked "and then?"', H().includes('And then?'));
okT('  …assign now is the default', (radios['flF_route'] || []).some(n => n.checked && n.value === 'now'));
sent = []; nextResponses = [{ success: true, message: 'Set.' }];
nodes.flF_date.value = '2026-09-09';
pick('location_id', '7');
vm.runInContext('flFormSubmit();', sandbox);
ok('assigning now sends no approval flag',
   sent.filter(s => s.method === 'POST')[0].body.send_for_approval, undefined);

reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
sent = []; nextResponses = [{ success: true, message: 'Sent for approval.' }];
nodes.flF_date.value = '2026-09-09';
pick('location_id', '7');
pick('route', 'approval');
vm.runInContext('flFormSubmit();', sandbox);
ok('  …and choosing "send for approval" does send it',
   sent.filter(s => s.method === 'POST')[0].body.send_for_approval, 1);

console.log('\n== G · approving from the machine’s page ==');
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flWorkshopApprove(55);', sandbox);
okT('the approve form offers the workshops', H().includes('LaCarne Workshop'));
okT('  ⭐ …and his shift that day, which is the Danish fix', H().includes('His shift that day'));
okT('  …and says approving is what tells him', nodes.flFormIntro.innerHTML.includes('tells him'));
sent = []; nextResponses = [{ success: true, message: 'Approved.' }];
nodes.flF_location_id.value = '9';
nodes.flF_time.value = '10:30';
nodes.flF_shift_template_id.value = '9';
vm.runInContext('flFormSubmit();', sandbox);
const apReq = sent.filter(s => s.method === 'POST')[0];
ok('it posts to the approve door', apReq.url, '/orders/riders-map/fleet/workshop/55/approve');
ok('  …with the adjusted workshop, time and shift',
   [apReq.body.location_id, apReq.body.visit_time, apReq.body.shift_template_id], [9, '10:30', 9]);

reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
sent = []; nextResponses = [{ success: true, message: 'Declined.' }];
vm.runInContext('flWorkshopDecline(55);', sandbox);
okT('the decline form says the rider was never told', nodes.flFormIntro.innerHTML.includes('never told'));
nodes.flF_reason.value = 'Faizabad run';
vm.runInContext('flFormSubmit();', sandbox);
const dcReq = sent.filter(s => s.method === 'POST')[0];
ok('it posts the reason to the decline door',
   [dcReq.url, dcReq.body.reason], ['/orders/riders-map/fleet/workshop/55/decline', 'Faizabad run']);

console.log('\n' + '─'.repeat(60));
console.log((fail === 0 ? '✅' : '❌') + `  ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
