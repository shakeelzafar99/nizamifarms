/**
 * Drive the REAL Issues-board page JS (extracted from fleet-issues.blade.php) against a fake
 * DOM and a stubbed endpoint, and assert what it actually renders.
 *
 * ⚠ Parsing is not running. The Vehicles tab shipped a "no chip" false alarm and the workshop
 *   round shipped an unmounted banner, both on code that parsed perfectly. This executes it.
 *
 * Usage: node scratchpad/issboard_harness.cjs
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
const ok = (what, cond, extra) => {
    if (cond) { pass++; console.log('  ✓ ' + what); }
    else { fail++; console.log('  ✗ ' + what + (extra ? '\n      ' + extra : '')); }
};
const section = t => console.log('\n== ' + t + ' ==');

// ── the fake DOM ─────────────────────────────────────────────────────────────
// ⚠ getElementById AUTO-CREATES. The real markup is built as innerHTML, so a node the code
//   writes into does not exist until something has drawn it — a stub that returns null instead
//   reports a working build as broken (the documented harness trap on this very screen).
const nodes = {};
function mkNode(id) {
    return {
        id, innerHTML: '', textContent: '', value: '',
        style: { display: '' }, dataset: {}, className: '',
        classList: { toggle() {}, add() {}, remove() {}, contains: () => false },
        appendChild() {}, querySelector: () => null, addEventListener() {},
    };
}
const document = {
    getElementById(id) { return nodes[id] || (nodes[id] = mkNode(id)); },
    createElement: () => mkNode('tmp'),
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener() {},
};
// flIssEsc uses createElement + textContent → innerHTML. Give that the real escaping.
document.createElement = () => {
    const n = mkNode('esc');
    let t = '';
    Object.defineProperty(n, 'textContent', {
        get: () => t,
        set(v) {
            t = String(v);
            n.innerHTML = t.replace(/&/g, '&amp;').replace(/</g, '&lt;')
                           .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        },
    });
    return n;
};

// ── the stubbed endpoint ─────────────────────────────────────────────────────
let lastUrl = null;
const PAYLOAD = {
    success: true, available: true, can_manage: true, can_schedule: true, can_approve: false,
    threads: true, read_only: false,
    totals: { machines: 8, with_issues: 3, quiet: 5, open_tickets: 4, urgent: 1, unanswered: 1,
              waiting_on_us: 2, waiting_on_rider: 1, stale: 1, workshop_booked: 1,
              workshop_missed: 1, workshop_done_open: 1, proposed: 0, closed: 6 },
    vehicles: [
        { id: 2, name: 'BCN-5755', vtype: 'bike', is_company: true, is_active: true,
          keeper_user_id: 77, keeper_name: 'Arslan Aslam',
          attention: { rank: 0, level: 'red', line: '🔴 Not rideable — 2 days since it was reported', age_hours: 48 },
          open_tickets: [
            { id: 13, title: 'Tayre bilkul "farg" ho gay <b>h</b>', urgent: true, status: 'open',
              is_open: true, unread: 2, age_hours: 72, waiting_on: 'us', waiting_hours: 72,
              unanswered: true, is_stale: true, vehicle_id: 2,
              last_message: { id: 64, author_name: 'Arslan Aslam', kind: 'photo', snippet: '📷 photo',
                              created_at: '2026-09-12 20:38:23' } },
            { id: 12, title: 'Hed salndr ka Kam h', urgent: false, status: 'acknowledged',
              is_open: true, unread: 0, age_hours: 96, waiting_on: 'us', waiting_hours: 96,
              unanswered: false, is_stale: true, vehicle_id: 2,
              last_message: { id: 58, author_name: 'Arslan Aslam', kind: 'text', snippet: 'Ok',
                              created_at: '2026-09-11 13:01:42' } },
          ],
          workshop: { id: 6, visit_date: '2026-09-16', visit_time: '09:00', workshop_label: 'Rawalpindi',
                      status: 'accepted', accepted: true, is_missed: false, is_proposed: false,
                      is_today: false, is_tomorrow: true, rider_name: 'Arslan Aslam',
                      /* 🔗 Part B: what the day is actually for. */
                      tickets: [{ id: 13, title: 'Tayre farg', status: 'scheduled', is_open: true },
                                { id: 12, title: 'Hed salndr', status: 'scheduled', is_open: true }] },
          last_done_visit: null, closed_count: 5, last_closed_at: '2026-09-08 23:36:41' },
        { id: 1, name: 'AY-4771', vtype: 'bike', is_company: true, is_active: true,
          keeper_user_id: 76, keeper_name: 'Kanan Anoos',
          attention: { rank: 4, level: 'amber', line: '🔧 Workshop done 11 Sep, 1 issue still open', age_hours: 0 },
          open_tickets: [
            { id: 5, title: 'Tyre end hai', urgent: false, status: 'acknowledged', is_open: true,
              unread: 0, age_hours: 216, waiting_on: 'us', waiting_hours: 216, unanswered: false,
              is_stale: true, vehicle_id: 1,
              last_message: { id: 40, author_name: 'Qasim', kind: 'text', snippet: 'Next Week',
                              created_at: '2026-09-06 19:22:19' } },
          ],
          workshop: null,
          last_done_visit: { id: 5, visit_date: '2026-09-11', workshop_label: 'Tahir Autos',
                             outcome_note: null, status: 'done', done_at: '2026-09-11 15:02:17' },
          closed_count: 0, last_closed_at: null },
        { id: 10, name: 'EGL-682', vtype: 'bike', is_company: true, is_active: true,
          keeper_user_id: 84, keeper_name: 'Danish Ali',
          attention: { rank: 6, level: 'blue', line: '💬 Waiting on Danish Ali for 5 days', age_hours: 120 },
          open_tickets: [
            { id: 7, title: 'Petrol over kaar raha hai', urgent: false, status: 'acknowledged',
              is_open: true, unread: 0, age_hours: 144, waiting_on: 'rider', waiting_hours: 120,
              unanswered: false, is_stale: true, vehicle_id: 10,
              last_message: { id: 50, author_name: 'Qasim', kind: 'text', snippet: 'Kesy Or Kider Sey',
                              created_at: '2026-09-10 12:36:58' } },
          ],
          workshop: { id: 9, visit_date: '2026-09-14', status: 'accepted', accepted: true,
                      is_missed: true, is_proposed: false, workshop_label: 'Tahir Autos' },
          last_done_visit: null, closed_count: 2, last_closed_at: '2026-09-11 14:29:33' },
    ],
    quiet: [{ id: 4, name: 'CAD-2958', keeper_name: null, is_company: true, closed_count: 2 },
            { id: 6, name: "Asim - own bike", keeper_name: 'Asim Tahir', is_company: false, closed_count: 0 }],
    history: [],
};
const HISTORY = {
    success: true, available: true, can_manage: true, threads: true, read_only: false,
    totals: PAYLOAD.totals, vehicles: [], quiet: [],
    history: [{ id: 3, vehicle_id: 2, vehicle_name: 'BCN-5755', title: 'Old seat problem',
                opened_at: '2026-09-05 14:31:40', closed_at: '2026-09-08 23:36:41',
                close_note: 'Seat re-covered', opened_by_name: 'Arslan Aslam',
                opened_for_name: 'Arslan Aslam', status: 'closed',
                // 💬 the server-composed context line — what a history row prints now
                context_line: 'Opened 5 Sep by Arslan Aslam · Closed 8 Sep by Qasim — “Seat re-covered”',
                message_count: 4 }],
};

let navigatedTo = null;
const window = { FL_ISS_INLINE_THREADS: true, location: { set href(v) { navigatedTo = v; }, get href() { return navigatedTo; } } };

function fetchStub(url) {
    lastUrl = url;
    const body = url.includes('mode=history') ? HISTORY : PAYLOAD;
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

// ── load the real code ───────────────────────────────────────────────────────
const src = fs.readFileSync('resources/views/pages/riders-map/partials/fleet-issues.blade.php', 'utf8');
const block = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].pop();
if (!block) { console.log('no script block found'); process.exit(1); }

const sandbox = {
    document, window, fetch: fetchStub, console,
    setInterval: () => 0, clearInterval: () => {}, setTimeout: (fn) => fn(),
    encodeURIComponent, flOpenTicket: null, flCloseTicket: null, flScheduleWorkshop: null,
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(block[1], sandbox, { filename: 'fleet-issues.blade.php' });

// Record what the host-provided functions are called with.
let openedTicket = null, closedTicket = null, scheduled = null;
sandbox.flOpenTicket = (id, wrap) => { openedTicket = { id, wrap: wrap && wrap.id }; };
sandbox.flCloseTicket = (id, unread) => { closedTicket = { id, unread }; };
sandbox.flScheduleWorkshop = (uid, name, vid) => { scheduled = { uid, name, vid }; };

const html = () => nodes['flIssCards'].innerHTML;
const strip = () => nodes['flIssStrip'].innerHTML;

// ─────────────────────────────────────────────────────────────────────────────
(async () => {

section('§1 it renders at all');
sandbox.flIssLoad();
await new Promise(r => setImmediate(r));
ok('the cards box was filled', html().length > 500, 'len=' + html().length);
ok('every machine with issues is drawn',
   ['BCN-5755', 'AY-4771', 'EGL-682'].every(p => html().includes(p)));
ok('the quiet machines are listed and counted',
   nodes['flIssQuiet'].innerHTML.includes('2') && nodes['flIssQuiet'].innerHTML.includes('CAD-2958'));
ok('the meta line summarises the fleet', nodes['flIssMeta'].innerHTML.includes('8 machines'));

section('§2 the attention line and the stripe agree');
ok('the red card carries the red class', html().includes('fl-iss-card red'));
ok('the amber card carries the amber class', html().includes('fl-iss-card amber'));
ok('the blue card carries the blue class', html().includes('fl-iss-card blue'));
ok('each attention sentence is printed',
   html().includes('Not rideable') && html().includes('still open') && html().includes('Waiting on Danish'));

section('§3 escaping — a title is user-typed and WILL contain quotes and tags');
ok('the ticket title is HTML-escaped, not injected',
   html().includes('&lt;b&gt;') && !html().includes('<b>h</b>'));
ok('  …and its quotes survive as entities', html().includes('&quot;farg&quot;'));

section('§4 whose turn is it, on screen');
ok('waiting on us is shown', html().includes('waiting on us'));
ok('waiting on the rider names his first name only', html().includes('waiting on Danish'));
ok('a photo-only last message says so', html().includes('📷 photo'));
ok('the unread badge is drawn', html().includes('>2</span>'));

section('§5 the workshop line');
ok('tomorrow is shown as a tag', html().includes('🔧 Tomorrow ✓✓'));
ok('a missed day is shown as a tag', html().includes('❗ Missed'));
ok('a DONE visit with no note says "no outcome note"', html().includes('no outcome note'));
ok('a machine with issues and nothing booked offers Schedule',
   html().includes('Schedule workshop'));

section('§5b Part B — the card says what the workshop day is FOR');
ok('the covers line names the issues', html().includes('covers: Tayre farg, Hed salndr'), html().slice(0,0));
ok('  …and a machine whose visit covers nothing shows no covers line',
   (html().match(/covers:/g) || []).length === 1);

/**
 * §5c ⭐⭐ THE CARDS OPEN COLLAPSED (owner, 15-Sep). A card used to print every ticket row, the
 *     workshop line, the Schedule button and the closed footer at once, so the sixth machine in
 *     trouble sat several screens down on a view whose whole job is "glance and know".
 *
 * ⚠ Asserted on the RENDERED MARKUP, which is the real contract. The stub's getElementById
 *   AUTO-CREATES with display:'' (see the note at the top), so a node read back from it says
 *   nothing about what the browser was actually handed.
 */
section('§5c the cards open collapsed');
ok('a RED machine is open from the start — the worst card is never behind a click',
   html().includes('<div id="flIssBody2">'));
ok('  …and shows the open chevron', html().includes('>▾</span>'));
ok('a quiet machine is collapsed',
   html().includes('<div id="flIssBody1" style="display:none;">')
   && html().includes('<div id="flIssBody10" style="display:none;">'));
ok('  …and shows the closed chevron', html().includes('>▸</span>'));
ok('the attention sentence stays OUTSIDE the collapsed body — the verdict is never hidden',
   html().indexOf('Workshop done 11 Sep, 1 issue still open') < html().indexOf('flIssBody1"'));
ok('the unread count rides the HEADER, where a collapsed card still shows it',
   html().indexOf('>2</span>') < html().indexOf('id="flIssBody2"'));
ok('every card offers the machine\'s own panel explicitly',
   (html().match(/Open this bike/g) || []).length === 3);
ok('  …and the old duplicate link in the workshop row is gone',
   !html().includes('open the machine'));

// The user's OWN choice beats the automatic one, in both directions.
sandbox.flIssExpanded[1] = true;
sandbox.flIssExpanded[2] = false;
sandbox.flIssRender();
ok('a card he opened himself stays open', html().includes('<div id="flIssBody1">'));
ok('a RED card he closed himself stays closed',
   html().includes('<div id="flIssBody2" style="display:none;">'));

// ⚠ Toggling flips the DOM directly rather than re-rendering, so an inline thread open in
//   ANOTHER card survives. Pre-seed the node the way the browser would have parsed it.
// ⚠ getElementById AUTO-CREATES (see the top of this file) — going through it is what puts the
//   node in the registry at all, so the real code and the test are looking at the same object.
const body10 = document.getElementById('flIssBody10');
const chev10 = document.getElementById('flIssChev10');
body10.style.display = 'none';
sandbox.flIssToggleCard(10);
ok('a click opens the body without a re-render',
   body10.style.display === '' && sandbox.flIssExpanded[10] === true);
ok('  …and flips the chevron', chev10.textContent === '▾');
sandbox.flIssToggleCard(10);
ok('a second click closes it again',
   body10.style.display === 'none' && sandbox.flIssExpanded[10] === false);

// A filter IS the drill-in — it must not cost a second click per card.
sandbox.flIssExpanded = {};
sandbox.flIssSetFilter('waiting_on_us');
// ⚠ Only the CARD bodies — the Chat box inside each card is hidden by default and always in the DOM.
ok('a filter opens what it found',
   !/id="flIssBody\d+" style="display:none;"/.test(html()) && html().includes('id="flIssBody'));
sandbox.flIssSetFilter('all');
sandbox.flIssExpanded = {};
sandbox.flIssRender();

section('§6 close from the board — the one engine');
ok('a Close button is drawn for an open ticket', html().includes('flIssClose(13,2)'));
sandbox.flIssClose(13, 2);
ok('  …and it calls the SHARED flCloseTicket, posting nothing itself',
   closedTicket && closedTicket.id === 13, JSON.stringify(closedTicket));
ok('  …passing the unread count so the dialog can warn', closedTicket.unread === 2);

section('§7 opening a thread uses the ONE renderer, into a per-card target');
sandbox.flIssOpenTicket(13, 2);
ok('flOpenTicket was called', openedTicket && openedTicket.id === 13);
ok('  …with a PER-TICKET wrapper, not the single shared box',
   openedTicket.wrap === 'flIssThread13', openedTicket && openedTicket.wrap);
sandbox.flIssOpenTicket(13, 2);
ok('  …and a second tap closes it', nodes['flIssThread13'].innerHTML === '');

section('§8 filters');
sandbox.flIssSetFilter('urgent');
ok('the urgent filter keeps only the urgent machine',
   html().includes('BCN-5755') && !html().includes('EGL-682'));
sandbox.flIssSetFilter('workshop_done_open');
ok('the done-but-open filter finds AY-4771',
   html().includes('AY-4771') && !html().includes('BCN-5755'));
sandbox.flIssSetFilter('all');
ok('all restores every card', ['BCN-5755', 'AY-4771', 'EGL-682'].every(p => html().includes(p)));

section('§9 history mode is clearly different');
sandbox.flIssSetFilter('closed');
await new Promise(r => setImmediate(r));
ok('it asked the server for history', lastUrl.includes('mode=history'), lastUrl);
ok('closed rows are drawn', html().includes('Old seat problem'));
ok('  …struck through and greyed, not as live cards',
   html().includes('line-through') && html().includes('fl-iss-card grey'));
ok('  …with the close note', html().includes('Seat re-covered'));
ok('  …and a way back to what is open', html().includes('back to what is open'));
sandbox.flIssSetFilter('all');
await new Promise(r => setImmediate(r));

section('§10 the read-only door renders no way in');
const RO = JSON.parse(JSON.stringify(PAYLOAD));
RO.can_manage = false; RO.can_schedule = false; RO.threads = false; RO.read_only = true;
RO.vehicles.forEach(v => v.open_tickets.forEach(t => { t.unread = 0; }));
sandbox.flIssData = RO;
sandbox.flIssRender();
ok('no Close button anywhere', !html().includes('flIssClose('));
ok('no rows are clickable', !html().includes('fl-iss-row clickable'));
ok('no Schedule button', !html().includes('Schedule workshop'));
ok('and it says so in the meta line', nodes['flIssMeta'].innerHTML.includes('view only'));

section('§11 the standalone page links out instead of rendering a second thread');
sandbox.window.FL_ISS_INLINE_THREADS = false;
sandbox.flIssData = PAYLOAD;
sandbox.flIssOpenTicket(13, 2);
ok('it navigates into Bikes with both ids',
   String(navigatedTo).includes('#bikes?vehicle=2') && String(navigatedTo).includes('ticket=13'),
   String(navigatedTo));
sandbox.flIssOpenVehicle(10);
ok('and a plate links to that machine', String(navigatedTo).includes('#bikes?vehicle=10'));

section('§12 the poll never wipes work in progress');
sandbox.window.FL_ISS_INLINE_THREADS = true;
sandbox.flIssOpenThreadId = null;
document.getElementById('flTicketReply').value = '';
ok('idle board is not busy', sandbox.flIssBusy() === false);
sandbox.flIssOpenThreadId = 13;
ok('an open thread counts as busy', sandbox.flIssBusy() === true);
sandbox.flIssOpenThreadId = null;
document.getElementById('flTicketReply').value = 'half typed reply';
ok('a half-typed reply counts as busy', sandbox.flIssBusy() === true);
document.getElementById('flTicketReply').value = '   ';
ok('  …but whitespace alone does not', sandbox.flIssBusy() === false);

/**
 * §13 ⭐ REFRESH AFTER SOMETHING CHANGED ON THE BOARD (15-Sep, later). The plan said the board
 *     refreshes after a booking, a reply or a close; only close did. And a reload must not throw
 *     away the thread the manager was answering in — cards collapse now, so its card has to be
 *     forced open and the thread put back in its own box.
 */
section('§13 flIssRefresh redraws without losing the open thread');
document.getElementById('flTicketReply').value = '';
sandbox.flIssFilter = 'all'; sandbox.flIssMode = 'live'; sandbox.flIssExpanded = {};
sandbox.flIssLoad(); await new Promise(r => setImmediate(r));
// Open ticket 5 (AY-4771, amber ⇒ collapsed by default) inline, then refresh.
sandbox.flIssExpanded[1] = true; sandbox.flIssRender();
openedTicket = null;
sandbox.flIssOpenTicket(5, 1);
ok('a thread is open in the board', sandbox.flIssOpenThreadId === 5 && openedTicket && openedTicket.id === 5);
sandbox.flIssExpanded = {};               // forget the user's choice: the refresh must restore it itself
openedTicket = null;
sandbox.flIssRefresh(); await new Promise(r => setImmediate(r));
ok('the cards were redrawn', html().includes('fl-iss-card'));
ok('  …with the thread\'s card forced OPEN', html().includes('<div id="flIssBody1">'));
ok('  …and the same thread re-opened into its own box',
   openedTicket && openedTicket.id === 5 && openedTicket.wrap === 'flIssThread5', JSON.stringify(openedTicket));
ok('  …and remembered as open again', sandbox.flIssOpenThreadId === 5);

// With no board loaded at all (Bikes tab just rendered), it only refreshes the counts.
let badge = null; sandbox.flIssBadgeFrom = t => { badge = t; };
sandbox.flIssData = null; sandbox.flIssOpenThreadId = null;
sandbox.flIssRefresh(); await new Promise(r => setImmediate(r));
ok('with no board loaded it refreshes only the counts', sandbox.flIssData === null);
ok('  …and still feeds the tab badge — the mount-time call the Bikes tab now makes',
   badge && typeof badge.waiting_on_us === 'number');

/**
 * §14 💬 CHAT (owner, 15-Sep): an explicit button, dated closed rows, clickable history rows and
 *     quiet-row counts — "more explicit for users to understand" than "✓ 5 closed · Show ›".
 */
section('§14 the Chat button, and a history that reads like a conversation');
sandbox.window.FL_ISS_INLINE_THREADS = true;
sandbox.flIssFilter = 'all'; sandbox.flIssMode = 'live'; sandbox.flIssExpanded = {}; sandbox.flIssClosedOpen = {};
sandbox.flIssLoad(); await new Promise(r => setImmediate(r));
ok('the card offers 💬 Chat with EVERY conversation counted (2 open + 5 closed)', html().includes('💬 Chat (7)'));
ok('  …and the old "✓ N closed · Show ›" footer is gone', !html().includes('Show ›'));
ok('a quiet machine with history shows its count as the way in', nodes['flIssQuiet'].innerHTML.includes('💬2'));
// ⚠⚠ The toggle is DOM-ONLY. A thread open in ANOTHER card must survive a Chat press, and
//    flIssOpenThreadId must not be left pointing at a wiped box (the poll would then think a thread
//    was open forever). Open one, press Chat elsewhere, and check nothing was redrawn.
sandbox.flIssExpanded[10] = true; sandbox.flIssRender();
openedTicket = null; sandbox.flIssOpenTicket(7, 10);
const cardsBefore = html();
const chatBox = document.getElementById('flIssChat2'); chatBox.style.display = 'none';
sandbox.flIssToggleClosed(2); await new Promise(r => setImmediate(r));
ok('pressing Chat on one card redraws NOTHING', html() === cardsBefore);
ok('  …so the thread open in the other card is untouched', sandbox.flIssOpenThreadId === 7);
ok('  …and the Chat box is simply shown', chatBox.style.display === '' && document.getElementById('flIssChatChev2').textContent === ' ▾');
ok('  …with the earlier conversations fetched INTO it', lastUrl.includes('mode=history&vehicle_id=2'));
const rows = document.getElementById('flIssChatRows2').innerHTML;
ok('  …each printed with the server\'s context line and a message count',
   rows.includes('Closed 8 Sep by Qasim') && rows.includes('<b>4</b> message'));
ok('  …with its own thread box, so it opens in place', rows.includes('id="flIssThread3"'));
ok('the divider is in the card from the start (hidden, never re-rendered in)', html().includes('Earlier conversations, closed'));
// A board reload must DROP the cached rows and re-read them, or a conversation closed a moment
// ago never appears under Chat until the page is reloaded.
lastUrl = ''; sandbox.flIssOpenThreadId = null;
sandbox.flIssLoad(); await new Promise(r => setImmediate(r));
ok('a board reload drops the closed-row cache and re-reads the open Chat', lastUrl.includes('mode=history&vehicle_id=2'));
sandbox.flIssSetFilter('closed'); await new Promise(r => setImmediate(r));
ok('history-mode rows open the thread (this was the web\'s one dead end)',
   html().includes('onclick="flIssOpenTicket(3,2)"') && html().includes('id="flIssThread3"'));
ok('  …and print the same context line', html().includes('Closed 8 Sep by Qasim'));
sandbox.flIssSetFilter('all'); sandbox.flIssClosedOpen = {};

console.log('\n────────────────────────────────────────────');
console.log((fail === 0 ? '✅  ' : '❌  ') + pass + ' passed, ' + fail + ' failed');
console.log('────────────────────────────────────────────');
process.exit(fail === 0 ? 0 : 1);

})();
