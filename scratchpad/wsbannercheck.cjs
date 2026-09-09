/**
 * Drive the REAL approval card in resources/views/partials/workshop-alerts.blade.php.
 *
 * ⚠ That partial is an IIFE, so nothing inside it is reachable from outside. The wrapper is
 *   stripped here and the body run directly, which is the only way to call the page's OWN
 *   renderer and its OWN click handlers rather than a copy of them — the whole point.
 *
 * ⚠ This partial also ships to the SHIFT PLANNER and ATTENDANCE pages, where the fleet
 *   screen's flForm() does not exist. Anything it reached for there would be undefined at
 *   runtime and silently dead, which a linter cannot see.
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

function mkNode(tag) {
    const n = {
        tagName: (tag || 'div').toUpperCase(), _html: '', _text: '', style: {cssText: '', display: '', background: ''},
        /**
         * ⚠ THE ESCAPER RUNS THROUGH HERE. The partial's own esc() does
         *   `d.textContent = s; return d.innerHTML;` — so a fake node whose textContent does
         *   not reach innerHTML makes esc() return '' for everything, and every card comes out
         *   blank. That is a harness bug that looks exactly like a broken renderer.
         */
        get textContent() { return this._text; },
        set textContent(v) {
            this._text = String(v == null ? '' : v);
            this._html = this._text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },
        children: [], _l: {}, disabled: false, value: '',
        get innerHTML() { return this._html; },
        set innerHTML(v) { this._html = String(v); },
        appendChild(c) { this.children.push(c); return c; },
        remove() { this._removed = true; },
        addEventListener(e, f) { (this._l[e] = this._l[e] || []).push(f); },
        // The card looks its own parts up by [data-…] selectors; serve them from a registry
        // populated when the card's HTML is set.
        querySelector(sel) { return (this._q && this._q[sel]) || null; },
        querySelectorAll() { return []; },
        getAttribute() { return 'csrf'; },
        setAttribute() {},
        click() { (this._l.click || []).forEach(f => f.call(this)); },
    };
    return n;
}
/** Give a card node the [data-…] children its own code asks for. */
function equip(card) {
    card._q = {};
    ['[data-say]', '[data-panel]', '[data-btns]', '[data-a="ok"]', '[data-a="adj"]', '[data-a="no"]']
        .forEach(sel => { card._q[sel] = mkNode('div'); card._q[sel]._q = {}; });
    // ⚠ Match the markup: the card writes these two with style="display:none". The Adjust
    //   handler TOGGLES on that value, so a panel that starts open would close on first click.
    card._q['[data-panel]'].style.display = 'none';
    card._q['[data-say]'].style.display = 'none';
    const panel = card._q['[data-panel]'];
    // The panel builds its own inner fields as innerHTML; serve those too.
    const origSet = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(panel) || {}, 'innerHTML');
    Object.defineProperty(panel, 'innerHTML', {
        get() { return this._html; },
        set(v) {
            this._html = String(v);
            this._q = {};
            ['[data-f="loc"]', '[data-f="time"]', '[data-f="tpl"]', '[data-f="why"]', '[data-f="go"]']
                .forEach(sel => { if (this._html.includes(sel.slice(1, -1).replace('=', '="').replace(/"$/, '') ) || this._html.includes(sel.replace(/[[\]]/g, ''))) {} });
            // simpler: always provide them; the card only touches the ones it just wrote.
            ['[data-f="loc"]', '[data-f="time"]', '[data-f="tpl"]', '[data-f="why"]', '[data-f="go"]']
                .forEach(sel => { this._q[sel] = mkNode('input'); });
        },
    });
    return card;
}

const boxes = { wsApprovals: mkNode('div'), wsAlerts: mkNode('div'), nfCornerStack: mkNode('div') };
let sent = [];
let nextResponses = [];
function sync(value) {
    return {
        then(onOk) {
            let v = value;
            try { v = onOk ? onOk(value) : value; }
            catch (e) { fail++; console.log('  ✗ page code threw: ' + e.message); return sync(undefined); }
            return (v && typeof v.then === 'function') ? v : sync(v);
        },
        catch() { return this; },
    };
}
const sandbox = {
    console,
    document: {
        getElementById: id => boxes[id] || null,
        querySelector: () => mkNode('meta'),
        querySelectorAll: () => [],
        createElement: tag => equip(mkNode(tag)),
        body: mkNode('body'),
    },
    fetch(url, opts) {
        sent.push({ url, method: (opts && opts.method) || 'GET', body: opts && opts.body ? JSON.parse(opts.body) : null });
        return sync({ ok: true, json: () => sync(nextResponses.shift() || { success: true }) });
    },
    setTimeout: fn => { try { fn(); } catch (e) {} }, setInterval() {}, clearInterval() {},
    localStorage: { getItem: () => null, setItem() {} },
    encodeURIComponent, parseInt, parseFloat, JSON, Date, Math, String, Number, Boolean, Array, Object, RegExp,
    alert: () => { fail++; console.log('  ✗ a browser alert() was used'); },
    confirm: () => { fail++; console.log('  ✗ a browser confirm() was used'); return true; },
    prompt: () => { fail++; console.log('  ✗ a browser prompt() was used'); return ''; },
};
sandbox.window = sandbox; sandbox.globalThis = sandbox;

const file = 'C:/NF App/nizamifarms/resources/views/partials/workshop-alerts.blade.php';
const src = fs.readFileSync(file, 'utf8');
// The BIG block is the second <script>; strip its IIFE wrapper so its internals are callable.
const blocks = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(m => m[1]);
let body = blocks[blocks.length - 1].trim();
body = body.replace(/^\(function\(\)\{/, '').replace(/\}\)\(\);?$/, '');
vm.createContext(sandbox);
try { vm.runInContext(body, sandbox, { filename: 'workshop-alerts.blade.php' }); }
catch (e) { console.log('  ✗ the partial threw while loading: ' + e.message); process.exit(1); }

const PENDING = {
    success: true, can_approve: true,
    workshops: [{ id: 7, name: 'LaCarne Workshop' }, { id: 9, name: 'Bilal Auto' }],
    shifts: [{ id: 1, name: 'Primary 11 AM', start: '11:00' }, { id: 9, name: 'LaCarne', start: '09:30' }],
    pending: [{ id: 55, rider_name: 'Danish', vehicle_name: 'AY-4771', visit_date: '2026-09-07',
                visit_time: '09:00', location_id: 7, proposed_by_name: 'Qasim',
                warnings: ['That is his weekly off day.'] }],
};

console.log('\n== A · a planner sees the card ==');
boxes.wsApprovals.children = [];
vm.runInContext('renderApprovals(' + JSON.stringify(PENDING) + ');', sandbox);
ok('one card was drawn', boxes.wsApprovals.children.length, 1);
const card = boxes.wsApprovals.children[0];
okT('it names the rider, the bike and the day',
    card.innerHTML.includes('Danish') && card.innerHTML.includes('AY-4771') && card.innerHTML.includes('2026-09-07'));
okT('it names who asked for it', card.innerHTML.includes('Qasim'));
okT('⭐ it shows the warning the booker was shown', card.innerHTML.includes('weekly off day'));
okT('it offers all three answers',
    card.innerHTML.includes('Approve') && card.innerHTML.includes('Adjust') && card.innerHTML.includes('Decline'));
okT('⚠ and it has NO dismiss control — an approval is a question, not a notice',
    !card.innerHTML.includes('data-dismiss'));

console.log('\n== B · a card with no registered workshop says so ==');
boxes.wsApprovals.children = [];
const noPin = JSON.parse(JSON.stringify(PENDING));
noPin.pending[0].location_id = null;
vm.runInContext('renderApprovals(' + JSON.stringify(noPin) + ');', sandbox);
okT('⭐ it warns that approving will not move his check-in place',
    boxes.wsApprovals.children[0].innerHTML.includes('will not move his check-in'));

console.log('\n== C · non-planners get nothing at all ==');
boxes.wsApprovals.children = [];
vm.runInContext('renderApprovals({success:true, can_approve:false, pending:[{id:1}]});', sandbox);
ok('nothing is drawn for someone who cannot approve', boxes.wsApprovals.children.length, 0);

console.log('\n== D · Approve posts straight through ==');
boxes.wsApprovals.children = [];
vm.runInContext('renderApprovals(' + JSON.stringify(PENDING) + ');', sandbox);
const c2 = boxes.wsApprovals.children[0];
sent = []; nextResponses = [{ success: true, message: 'Approved.' }, { success: true, can_approve: true, pending: [] }];
c2._q['[data-a="ok"]'].click();
ok('it posts to the approve door', sent[0].url, '/orders/riders-map/fleet/workshop/55/approve');
ok('  …with no forced changes', sent[0].body, {});

console.log('\n== E · Adjust opens the three changes IN the card ==');
boxes.wsApprovals.children = [];
vm.runInContext('renderApprovals(' + JSON.stringify(PENDING) + ');', sandbox);
const c3 = boxes.wsApprovals.children[0];
c3._q['[data-a="adj"]'].click();
const panel = c3._q['[data-panel]'];
ok('the panel is open', panel.style.display, '');
okT('it lists the workshops', panel.innerHTML.includes('LaCarne Workshop') && panel.innerHTML.includes('Bilal Auto'));
okT('  …the appointment time', panel.innerHTML.includes('Appointment time'));
okT('  ⭐ …and his shift that day — the Danish fix', panel.innerHTML.includes('His shift that day'));
sent = []; nextResponses = [{ success: true, message: 'Approved.' }, { success: true, can_approve: true, pending: [] }];
panel._q['[data-f="loc"]'].value = '9';
panel._q['[data-f="time"]'].value = '10:30';
panel._q['[data-f="tpl"]'].value = '9';
panel._q['[data-f="go"]'].click();
ok('approving with changes carries all three',
   [sent[0].body.location_id, sent[0].body.visit_time, sent[0].body.shift_template_id], [9, '10:30', 9]);

console.log('\n== F · Decline carries the reason ==');
boxes.wsApprovals.children = [];
vm.runInContext('renderApprovals(' + JSON.stringify(PENDING) + ');', sandbox);
const c4 = boxes.wsApprovals.children[0];
c4._q['[data-a="no"]'].click();
const p4 = c4._q['[data-panel]'];
okT('it asks why, and says the booker will see it', p4.innerHTML.includes('who asked will see this'));
sent = []; nextResponses = [{ success: true, message: 'Declined.' }, { success: true, can_approve: true, pending: [] }];
p4._q['[data-f="why"]'].value = 'Faizabad run';
p4._q['[data-f="go"]'].click();
ok('it posts the reason to the decline door',
   [sent[0].url, sent[0].body.reason], ['/orders/riders-map/fleet/workshop/55/decline', 'Faizabad run']);

console.log('\n== G · a refusal is shown in the card, not swallowed ==');
boxes.wsApprovals.children = [];
vm.runInContext('renderApprovals(' + JSON.stringify(PENDING) + ');', sandbox);
const c5 = boxes.wsApprovals.children[0];
sent = []; nextResponses = [{ success: false, message: 'Someone has already approved that one.' }];
c5._q['[data-a="ok"]'].click();
okT('the message lands in the card', c5._q['[data-say]'].textContent.includes('already approved'));
okT('  …and the card stays put so he can see it', !c5._removed);

console.log('\n' + '─'.repeat(60));
console.log((fail === 0 ? '✅' : '❌') + `  ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
