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
/**
 * 🔗 15-Sep (Part B): a `checklist` field is a MULTI-select group keyed by `data-flck`, not by
 *    `name` — deliberately, because `flFieldValue` already treats `input[name=…]` as a RADIO
 *    group and would hand back only the first ticked box.
 */
const checks = {};   // group key -> [nodes]
function parseInto(host, html) {
    /**
     * ⚠⚠ REPLACING innerHTML DESTROYS THE OLD NODES. A real browser drops them; this stub was
     *    only ever APPENDING, so after the done dialog was re-opened for a different visit the
     *    previous visit's tick-boxes were still in `checks` — and "the save carries no close
     *    ids" passed or failed depending on what had been rendered minutes earlier. A harness
     *    that accumulates state the browser would have thrown away tests a page that does not
     *    exist. Drop everything this host produced before re-parsing it.
     */
    Object.keys(checks).forEach(k => {
        checks[k] = (checks[k] || []).filter(n => n._host !== host);
        if (!checks[k].length) delete checks[k];
    });
    Object.keys(radios).forEach(k => {
        radios[k] = (radios[k] || []).filter(n => n._host !== host);
        if (!radios[k].length) delete radios[k];
    });

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
        n._host = host;          // ⚠ so a later innerHTML replacement can drop it — see above
        const flck = (attrs.match(/\bdata-flck="([^"]+)"/) || [])[1];
        if (id) { n.id = id; inputs[id] = n; nodes[id] = n; }
        if (name) { (radios[name] = radios[name] || []).push(n); }
        if (flck) { n.dataset = { flck }; (checks[flck] = checks[flck] || []).push(n); }
        // ✅ Ruling 6: the done dialog's "which of these are fixed?" boxes.
        if (/\bdata-wsclose="1"/.test(attrs)) {
            n.dataset = Object.assign(n.dataset || {}, { wsclose: '1' });
            (checks.__wsclose = checks.__wsclose || []).push(n);
        }
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
    if ((m = sel.match(/^input\[data-flck="([^"]+)"\]$/))) return checks[m[1]] || [];
    if (sel === 'input[data-wsclose="1"]:checked') return (checks.__wsclose || []).filter(n => n.checked);
    if (sel === 'input[data-wsclose="1"]') return checks.__wsclose || [];
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
/**
 * 🔗 15-Sep (Part B): the booking form now asks the WARNINGS endpoint for the machine's open
 *    issues before it draws, so it can offer them as tick-boxes.
 *
 * ⚠⚠ That broke a queue-ordered stub. `nextResponses.shift()` handed the workshops payload to
 *    whichever request happened to go first, so adding one fetch silently mis-answered every
 *    other one and six assertions failed on a working build. Route by URL instead: a stub that
 *    depends on call ORDER is a stub that breaks every time the page gains a request.
 */
let ticketsResponse = { success: true, open_tickets: [] };
/**
 * A FormData stand-in that records what was appended. ⚠ Repeated keys (`close_ticket_ids[]`)
 * collect into an ARRAY — which is the whole point of the assertions below, and what a real
 * FormData does on the wire.
 */
class FakeFormData {
    constructor() { this._d = {}; }
    append(k, v) {
        if (k.endsWith('[]')) { (this._d[k] = this._d[k] || []).push(String(v)); return; }
        this._d[k] = v;
    }
    get __data() { return this._d; }
}
function fetchStub(url, opts) {
    const raw = opts && opts.body;
    const body = raw instanceof FakeFormData ? raw.__data : (raw ? JSON.parse(raw) : null);
    sent.push({ url, method: (opts && opts.method) || 'GET', body });
    if (String(url).includes('/workshop/warnings')) {
        return sync({ ok: true, json: () => sync(ticketsResponse) });
    }
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
    Promise, FormData: FakeFormData,
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
    Object.keys(checks).forEach(k => delete checks[k]);
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


/* ═══════════════════════════════════════════════════════════════════════════
   🔗 PART B (15-Sep-2026) — "which reported issues is this trip for?"
   Until this round a visit linked at most the ONE ticket a manager pressed
   Schedule from, and since managers book from the machine's card rather than a
   thread, nothing was ever linked at all.
   ═══════════════════════════════════════════════════════════════════════════ */
console.log('\n== H · the booking form offers the machine’s open issues ==');
const ISSUES = [
    { id: 13, title: 'Tayre farg ho gay', urgent: true,  status: 'open',         opened_for_name: 'Arslan', already_linked: false },
    { id: 12, title: 'Hed salndr ka Kam', urgent: false, status: 'acknowledged', opened_for_name: 'Arslan', already_linked: false },
    { id: 9,  title: 'Set kharab h',      urgent: false, status: 'acknowledged', opened_for_name: 'Arslan', already_linked: true  },
];
ticketsResponse = { success: true, open_tickets: ISSUES, warnings: [] };
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);

okT('it asks which issues the trip is for', H().includes('Which reported issues is this trip for?'));
okT('  …listing every open one', H().includes('Tayre farg ho gay') && H().includes('Hed salndr ka Kam')
    && H().includes('Set kharab h'));
okT('  …marking the urgent one', H().includes('🔴 Tayre farg ho gay'));
okT('  …naming who reported it', H().includes('reported by Arslan'));
okT('  …and flagging one already on another day', H().includes('already on another workshop day'));
/**
 * ⭐ ALL TICKED BY DEFAULT — the bike going in for everything reported on it is the normal
 *   case, and a manager who has to tick three boxes to get the obvious outcome will stop
 *   bothering, which is how the links stop being made at all.
 */
okT('  ⭐ every box starts TICKED', (H().match(/data-flck="ticket_ids"[^>]*checked/g) || []).length === 3);

console.log('\n== I · booking sends exactly what was ticked ==');
sent = []; nextResponses = [{ success: true, message: 'Booked.' }];
nodes.flF_date.value = '2026-09-20';
pick('location_id', '7');
pick('route', 'now');
vm.runInContext('flFormSubmit();', sandbox);
let bReq = sent.find(s => s.method === 'POST' && s.url === '/orders/riders-map/fleet/workshop');
ok('all three ids ride along', (bReq.body.ticket_ids || []).slice().sort((a, b) => a - b), [9, 12, 13]);
ok('  …as numbers, not strings', typeof (bReq.body.ticket_ids || [])[0], 'number');

console.log('\n== J · unticking one leaves it in the queue ==');
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
// Untick the middle issue, exactly as a manager would.
(checks['ticket_ids'] || []).filter(c => String(c.value) === '12').forEach(c => { c.checked = false; });
sent = []; nextResponses = [{ success: true, message: 'Booked.' }];
nodes.flF_date.value = '2026-09-20';
pick('location_id', '7');
pick('route', 'now');
vm.runInContext('flFormSubmit();', sandbox);
bReq = sent.find(s => s.method === 'POST' && s.url === '/orders/riders-map/fleet/workshop');
ok('only the ticked ones are sent', (bReq.body.ticket_ids || []).slice().sort((a, b) => a - b), [9, 13]);

console.log('\n== J2 · unticking EVERY box still sends the answer ==');
/**
 * ⚠⚠ THE DISTINCTION THE SERVER RESTS ON (15-Sep-2026). "He unticked them all" and "this client
 *    is too old to have been asked" must not look the same on the wire: the first means the new
 *    day covers nothing, the second means carry the old day's complaints across as before. The
 *    server tells them apart by whether the `ticket_ids` KEY is present at all — so when the
 *    question was put on screen, the answer is sent even when it is empty.
 *
 *    Sending it only `if (length)` is what made an untick meaningless: a manager who narrowed a
 *    re-booking to nothing still had every complaint dragged onto the new day, reading
 *    "Workshop set" against a trip it was never for.
 */
ticketsResponse = { success: true, open_tickets: ISSUES, warnings: [] };
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
(checks['ticket_ids'] || []).forEach(c => { c.checked = false; });
sent = []; nextResponses = [{ success: true, message: 'Booked.' }];
nodes.flF_date.value = '2026-09-20';
pick('location_id', '7');
pick('route', 'now');
vm.runInContext('flFormSubmit();', sandbox);
bReq = sent.find(s => s.method === 'POST' && s.url === '/orders/riders-map/fleet/workshop');
okT('the key IS sent', Array.isArray(bReq.body.ticket_ids));
ok('  …and it is empty, not missing', bReq.body.ticket_ids, []);

console.log('\n== K · a machine with nothing reported asks nothing ==');
ticketsResponse = { success: true, open_tickets: [], warnings: [] };
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
okT('no issue question is drawn at all', !H().includes('Which reported issues'));
sent = []; nextResponses = [{ success: true, message: 'Booked.' }];
nodes.flF_date.value = '2026-09-20';
pick('location_id', '7');
pick('route', 'now');
vm.runInContext('flFormSubmit();', sandbox);
bReq = sent.find(s => s.method === 'POST' && s.url === '/orders/riders-map/fleet/workshop');
okT('  …and the booking carries no ticket_ids key', bReq.body.ticket_ids === undefined);

console.log('\n== L · the form still opens if the issue lookup fails ==');
/**
 * ⚠ A booking form that cannot open because a SIDE question failed would be a far worse bug
 *   than the one Part B fixes. It must degrade to "no boxes", never to "no form".
 */
ticketsResponse = null;   // fetchStub answers `{success:false}`-ish; the page must cope
reset({ success: true, workshops: WS, can_approve: true, approval_on: true, shifts: SHIFTS });
vm.runInContext('flScheduleWorkshop(null, null, 42);', sandbox);
okT('the form is drawn anyway', H().includes('Which day'));
okT('  …with no issue question', !H().includes('Which reported issues'));
ticketsResponse = { success: true, open_tickets: [] };


/* ═══════════════════════════════════════════════════════════════════════════
   ✅ RULING 6 (15-Sep-2026) — "and is the complaint fixed?" on the DONE dialog.
   A visit being done does NOT close a ticket by itself: only a manager closes
   one, and the RIDER can mark a visit done. So the manager is ASKED, with what
   the trip actually went in for pre-ticked (which Part B made knowable).
   ═══════════════════════════════════════════════════════════════════════════ */
/**
 * ⚠ The DONE dialog shows its outcome with a browser alert() — PRE-EXISTING, and outside the
 *   5-Sep round that removed dialogs from the BOOKING flow. Capture it here rather than failing
 *   on it, so the trap keeps guarding the flow it was written for while this section can still
 *   assert that the manager is actually told what happened.
 */
let alerted = [];
sandbox.alert = (m) => { alerted.push(String(m)); };

console.log('\n== M · the done dialog asks which issues are fixed ==');

// The done dialog is FIXED markup, not flForm — create the nodes it reaches for.
['flWsDone', 'flWsDoneMeter', 'flWsDoneAmount', 'flWsDoneNote', 'flWsDonePhoto', 'flWsDoneErr',
 'flWsDoneType', 'flWsDoneSource', 'flWsDoneIssues', 'flWsDoneSubmit'].forEach(id => {
    nodes[id] = mkNode('div'); nodes[id].id = id; nodes[id].dataset = {};
});
nodes.flWsDonePhoto.files = [];

const DONE_TYPES = {
    success: true, booked_type_id: null, vehicle_name: 'ZDN-900', class: 'bike',
    can_close_tickets: true,
    closeable_tickets: [
        { id: 31, title: 'Chain slipping',  urgent: true,  status: 'acknowledged', covered_by_this_visit: true },
        { id: 32, title: 'Seat torn',       urgent: false, status: 'acknowledged', covered_by_this_visit: true },
        { id: 33, title: 'Mirror loose',    urgent: false, status: 'open',         covered_by_this_visit: false },
    ],
    types: [{ id: 3, name: 'Chain Set', counts_down: true }],
    pay_sources: [],
};
nextResponses = [DONE_TYPES];
vm.runInContext('flWorkshopDone(55);', sandbox);

const DH = () => nodes.flWsDoneIssues.innerHTML;
okT('it asks which are fixed', DH().includes('Which of these are now fixed?'));
okT('  …listing every open issue on the machine',
    DH().includes('Chain slipping') && DH().includes('Seat torn') && DH().includes('Mirror loose'));
/**
 * ⭐ PRE-TICKED only for what the trip was FOR. The third is offered but unticked: the visit
 *   may have happened to fix it, but a manager tapping through must not close a complaint the
 *   bike never went in for.
 */
okT('  ⭐ the two the trip was for arrive TICKED',
    (DH().match(/data-wsclose="1" value="3[12]" checked/g) || []).length === 2);
okT('  …and the one it was not for does NOT',
    /data-wsclose="1" value="33"(?! checked)/.test(DH()));
okT('  …which is said in words, not just a tick', DH().includes('not part of this trip'));
okT('  …and it says the outcome note becomes the close note', DH().includes('becomes the close note'));

console.log('\n== N · saving sends exactly the ticked ids ==');
sent = []; nextResponses = [{ success: true, message: 'Done.' }];
nodes.flWsDoneNote.value = 'Chain replaced';
vm.runInContext('flWsDoneSave();', sandbox);
const doneReq = sent.find(s => s.method === 'POST');
ok('it posts to the done door', doneReq.url, '/orders/riders-map/fleet/workshop/55/done');
ok('  …carrying the two ticked ids', (doneReq.body['close_ticket_ids[]'] || []).slice().sort(),
   ['31', '32']);
ok('  …and the outcome note', doneReq.body.outcome_note, 'Chain replaced');
okT('  …and the manager is TOLD what happened', alerted.some(m => /Done/.test(m)));

console.log('\n== O · unticking one leaves it open ==');
(checks['__wsclose'] || []).filter(c => String(c.value) === '32').forEach(c => { c.checked = false; });
sent = []; nextResponses = [{ success: true, message: 'Done.' }];
vm.runInContext('flWsDoneSave();', sandbox);
const doneReq2 = sent.find(s => s.method === 'POST');
ok('only the still-ticked id is sent', doneReq2.body['close_ticket_ids[]'], ['31']);

console.log('\n== P · a viewer who cannot close tickets is not asked ==');
/**
 * ⚠⚠ The RIDER closing out his own visit must never see this question — that is the whole
 *    ruling. The server sends him an empty list; the dialog must then draw nothing at all,
 *    not an empty box that looks broken.
 */
nextResponses = [{ ...DONE_TYPES, can_close_tickets: false, closeable_tickets: [] }];
vm.runInContext('flWorkshopDone(56);', sandbox);
okT('nothing is drawn', DH() === '');
okT('  …and the block is hidden', nodes.flWsDoneIssues.style.display === 'none');
sent = []; nextResponses = [{ success: true, message: 'Done.' }];
vm.runInContext('flWsDoneSave();', sandbox);
const doneReq3 = sent.find(s => s.method === 'POST');
okT('  …and the save carries no close ids at all', doneReq3.body['close_ticket_ids[]'] === undefined);

console.log('\n== Q · reopening for another visit does not keep the last list ==');
/**
 * ⚠⚠ A stale pre-ticked list from the PREVIOUS visit would mean one Save closing another
 *    machine's complaints. The dialog clears it before every load.
 */
nextResponses = [DONE_TYPES];
vm.runInContext('flWorkshopDone(57);', sandbox);
okT('the list belongs to the visit just opened', DH().includes('Chain slipping'));
nextResponses = [{ success: false }];
vm.runInContext('flWorkshopDone(58);', sandbox);
okT('  …and a failed load leaves NO stale boxes', DH() === '');

console.log('\n' + '─'.repeat(60));
console.log((fail === 0 ? '✅' : '❌') + `  ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
