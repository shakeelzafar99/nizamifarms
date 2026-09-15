// Drives the REAL background-refresh IIFE out of the blade against a stub DOM.
// The guards are the safety-critical part of this feature: every one of them is
// the difference between "the pane quietly freshened" and "the pane moved while
// someone was approving money in it".
const fs = require('fs'), vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');

let pass = 0, fail = 0;
const ok = (name, cond, extra) => {
  if (cond) { pass++; console.log('  PASS ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra !== undefined ? '  -> ' + JSON.stringify(extra) : '')); }
};

// ── Minimal DOM ────────────────────────────────────────────────────────────
function El(id, opts) {
  opts = opts || {};
  const el = {
    id, tagName: opts.tag || 'DIV', style: {}, dataset: opts.dataset || {},
    // nodeType/parentNode matter: a real Selection's commonAncestorContainer is
    // usually a TEXT node, and the guard walks up to its element. A stub without
    // them silently reports "nothing selected" and the guard looks broken.
    nodeType: opts.nodeType === undefined ? 1 : opts.nodeType,
    get parentNode() { return el.parent; },
    textContent: '', _html: '', _classes: new Set(opts.classes || []),
    children: [], parent: null, scrollTop: 0, options: opts.options || [],
    selectedIndex: opts.selectedIndex === undefined ? 0 : opts.selectedIndex,
    value: opts.value,
    classList: {
      add: c => el._classes.add(c),
      remove: c => el._classes.delete(c),
      contains: c => el._classes.has(c),
      toggle: (c, force) => {
        if (force === undefined) { el._classes.has(c) ? el._classes.delete(c) : el._classes.add(c); }
        else if (force) { el._classes.add(c); } else { el._classes.delete(c); }
        return el._classes.has(c);
      }
    },
    get innerHTML() { return el._html; },
    set innerHTML(v) {
      el._html = v;
      // Re-create children from the markup's ids, the way a real swap would.
      el.children = [];
      const ids = [...String(v).matchAll(/id="([^"]+)"/g)].map(m => m[1]);
      const hiddenIds = [...String(v).matchAll(/id="([^"]+)" class="hidden"/g)].map(m => m[1]);
      ids.forEach(cid => {
        const child = El(cid, { classes: hiddenIds.includes(cid) ? ['hidden'] : [] });
        child.parent = el;
        el.children.push(child);
        DOM.byId[cid] = child;
      });
    },
    contains(node) {
      let n = node;
      while (n) { if (n === el) return true; n = n.parent; }
      return false;
    },
    querySelectorAll(sel) {
      const all = [];
      (function walk(e) { e.children.forEach(c => { all.push(c); walk(c); }); })(el);
      if (sel === '[id]') return all.filter(e => e.id);
      if (sel === 'select') return all.filter(e => e.tagName === 'SELECT');
      return [];
    },
    appendChild(c) { c.parent = el; el.children.push(c); DOM.byId[c.id] = c; return c; }
  };
  return el;
}

let DOM;
function buildDom() {
  DOM = { byId: {}, badges: [] };

  const state = El('dc-refresh-state', {
    dataset: { baseline: JSON.stringify({ deliveries: 5, proofs: 2, settled: 1, requests: 4 }), rider: 'all' }
  });
  DOM.byId['dc-refresh-state'] = state;

  ['requests', 'messages'].forEach(p => {
    const body = El('dc-body-' + p);
    DOM.byId['dc-body-' + p] = body;
    DOM.byId['dc-fresh-' + p] = El('dc-fresh-' + p);
  });

  // Groups the operator can collapse, one per pane.
  const g1 = El('petrol-requests-body'); DOM.byId['dc-body-requests'].appendChild(g1);
  const g2 = El('followup-older');       DOM.byId['dc-body-messages'].appendChild(g2);

  // Modals, all shut.
  ['fuBillsModal', 'proofModal', 'fmModal'].forEach(id => {
    const m = El(id); m.style.display = 'none'; DOM.byId[id] = m;
  });
  DOM.byId['waChatOverlay'] = El('waChatOverlay');

  // The rider closings BELOW the panes — a sentinel that must never be touched.
  DOM.byId['rider-closings-sentinel'] = El('rider-closings-sentinel');
  DOM.byId['rider-closings-sentinel'].innerHTML = '<div id="deposit-form-77">untouched</div>';

  DOM.badges = ['petrol', 'maint', 'reqamt', 'chase', 'proof'].map(k => {
    const b = El('badge-' + k, { dataset: { dcBadge: k } });
    b._badge = k;
    return b;
  });
  return DOM;
}

function makeCtx(opts) {
  buildDom();
  const ctx = {
    console,
    Date,
    Object, JSON, Math, Number, String, Array,
    document: {
      getElementById: id => DOM.byId[id] || null,
      querySelectorAll: sel => {
        const m = /\[data-dc-badge="([^"]+)"\]/.exec(sel);
        if (m) return DOM.badges.filter(b => b._badge === m[1]);
        return [];
      },
      querySelector: () => null,
      addEventListener: (ev, fn) => { ctx._listeners[ev] = fn; },
      get activeElement() { return ctx._activeElement || null; },
      hidden: false, body: { id: '__body__' }
    },
    window: {},
    getSelection: () => ctx._selection || { isCollapsed: true, rangeCount: 0 },
    fetch: url => {
      ctx._sent.push(url);
      const r = opts.responder(url);
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(r) });
    },
    setInterval: (fn) => { ctx._poll = fn; return 1; },
    setTimeout: (fn, ms) => { ctx._timers.push({ fn, ms }); return ctx._timers.length; },
    clearTimeout: () => {},
    encodeURIComponent
  };
  ctx.window = ctx;
  ctx._sent = []; ctx._timers = []; ctx._listeners = {};
  ctx._activeElement = null; ctx._selection = null;
  vm.createContext(ctx);
  vm.runInContext(code, ctx);
  return ctx;
}

const settle = async () => { for (let i = 0; i < 10; i++) await new Promise(r => setImmediate(r)); };
const runTimers = ctx => { const t = ctx._timers.splice(0); t.forEach(x => x.fn()); };

const PANELS = {
  success: true,
  requests_html: '<div id="petrol-requests-body"><div id="petrol-req-9">NEW REQUEST</div></div>',
  messages_html: '<div id="followup-older"><div id="fu-row-42">NEW ROW</div></div>',
  badges: { petrol_count: 6, maint_count: 0, req_amount: 4100, chase_count: 3, proof_count: 0 },
  heartbeat: { deliveries: 7, proofs: 2, settled: 1, requests: 6 }
};

const responder = (counts, panels) => url => {
  if (url.indexOf('followup-heartbeat') !== -1) return { success: true, counts };
  if (url.indexOf('panels-refresh') !== -1) return JSON.parse(JSON.stringify(panels || PANELS));
  return {};
};
const SAME    = { deliveries: 5, proofs: 2, settled: 1, requests: 4 };
const CHANGED = { deliveries: 7, proofs: 2, settled: 1, requests: 6 };
const hitPanels = ctx => ctx._sent.filter(u => u.indexOf('panels-refresh') !== -1).length;

(async function () {
  console.log('\n== 1. nothing changed -> nothing fetched, nothing swapped ==');
  {
    const c = makeCtx({ responder: responder(SAME) });
    c._poll(); await settle();
    ok('heartbeat polled', c._sent.some(u => u.indexOf('followup-heartbeat') !== -1));
    ok('panels NOT fetched', hitPanels(c) === 0);
    ok('requests pane untouched', DOM.byId['dc-body-requests'].innerHTML === '');
  }

  console.log('\n== 2. something changed -> both panes swap, badges follow ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    c._poll(); await settle();
    ok('panels fetched once', hitPanels(c) === 1);
    ok('requests pane swapped', DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
    ok('messages pane swapped', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
    ok('petrol badge updated', DOM.badges[0].textContent === '⛽ 6', DOM.badges[0].textContent);
    ok('zero badge hidden, not "0"', DOM.badges[1].classList.contains('hidden'));
    ok('amount badge formatted', DOM.badges[2].textContent.indexOf('4,100') !== -1, DOM.badges[2].textContent);
    ok('chase badge revealed', !DOM.badges[3].classList.contains('hidden') && DOM.badges[3].textContent === '3 to chase');
    ok('"updated" flash shown', DOM.byId['dc-fresh-requests'].classList.contains('dc-fresh-on'));
    ok('⭐ the rider closings below are untouched',
      DOM.byId['rider-closings-sentinel'].innerHTML.indexOf('untouched') !== -1);
  }

  console.log('\n== 3. re-baselines, so one change is not reported forever ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    c._poll(); await settle();
    ok('fetched once', hitPanels(c) === 1);
    c._poll(); await settle();
    ok('same counts now look unchanged', hitPanels(c) === 1, hitPanels(c));
  }

  console.log('\n== 4. a modal is open -> NOTHING swaps, and it is retried ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    DOM.byId['fuBillsModal'].style.display = 'flex';
    c._poll(); await settle();
    ok('panels were fetched', hitPanels(c) === 1);
    ok('requests NOT swapped', DOM.byId['dc-body-requests'].innerHTML === '');
    ok('messages NOT swapped', DOM.byId['dc-body-messages'].innerHTML === '');
    ok('a retry was scheduled', c._timers.length > 0);
    DOM.byId['fuBillsModal'].style.display = 'none';
    runTimers(c); await settle();
    ok('swaps once the modal closes', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
    ok('payload was reused, not refetched', hitPanels(c) === 1);
  }

  console.log('\n== 5. the WhatsApp chat drawer counts as a modal ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    DOM.byId['waChatOverlay'].classList.add('open');
    c._poll(); await settle();
    ok('nothing swapped while reading a chat', DOM.byId['dc-body-messages'].innerHTML === '');
    DOM.byId['waChatOverlay'].classList.remove('open');
    runTimers(c); await settle();
    ok('swaps after it closes', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
  }

  console.log('\n== 6. ⭐⭐ a half-chosen pay source freezes ONLY that pane ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    const sel = El('petrol-pay-src-185', {
      tag: 'SELECT',
      options: [{ value: '', defaultSelected: true }, { value: '12', defaultSelected: false }],
      value: '12', selectedIndex: 1
    });
    DOM.byId['dc-body-requests'].appendChild(sel);
    c._poll(); await settle();
    ok('requests pane FROZEN — the chosen bank survives', DOM.byId['dc-body-requests'].innerHTML === '');
    ok('messages pane still refreshes', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
    sel.value = '';                       // operator resets it to the default
    runTimers(c); await settle();
    ok('requests refreshes once the choice is cleared',
      DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
  }

  console.log('\n== 7. an untouched select does NOT freeze the pane ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    DOM.byId['dc-body-requests'].appendChild(El('petrol-pay-src-186', {
      tag: 'SELECT',
      options: [{ value: '', defaultSelected: true }, { value: '12', defaultSelected: false }],
      value: '', selectedIndex: 0
    }));
    c._poll(); await settle();
    ok('pane refreshed normally', DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
  }

  console.log('\n== 8. focus inside a pane freezes just that pane ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    const btn = El('some-button'); DOM.byId['dc-body-requests'].appendChild(btn);
    c._activeElement = btn;
    c._poll(); await settle();
    ok('focused pane frozen', DOM.byId['dc-body-requests'].innerHTML === '');
    ok('other pane refreshed', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
    c._activeElement = null;
    runTimers(c); await settle();
    ok('refreshes after focus leaves', DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
  }

  console.log('\n== 9. selected text inside a pane freezes it ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    const node = El('order-num'); DOM.byId['dc-body-messages'].appendChild(node);
    // A real selection hands back the TEXT node inside the element, not the
    // element — which is exactly the case the guard has to handle.
    const textNode = El('', { nodeType: 3 }); textNode.parent = node;
    c._selection = { isCollapsed: false, rangeCount: 1, getRangeAt: () => ({ commonAncestorContainer: textNode }) };
    c._poll(); await settle();
    ok('pane frozen while text is selected', DOM.byId['dc-body-messages'].innerHTML === '');
    ok('the other pane still refreshed', DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
  }

  console.log('\n== 10. a recent approval freezes its pane (quiet period) ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    c.dcPaneTouched('requests');
    c._poll(); await settle();
    ok('requests frozen right after an approval', DOM.byId['dc-body-requests'].innerHTML === '');
    ok('messages unaffected', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
    c.window.dcPaneQuietUntil.requests = Date.now() - 1;   // quiet period elapses
    runTimers(c); await settle();
    ok('refreshes once the quiet period ends',
      DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
  }

  console.log('\n== 11. collapsed groups and scroll survive the swap ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    DOM.byId['petrol-requests-body'].classList.add('hidden');   // operator collapsed it
    DOM.byId['dc-body-requests'].scrollTop = 240;
    c._poll(); await settle();
    ok('swap happened', DOM.byId['dc-body-requests'].innerHTML.indexOf('NEW REQUEST') !== -1);
    ok('the group is still collapsed', DOM.byId['petrol-requests-body'].classList.contains('hidden'));
    ok('scroll position kept', DOM.byId['dc-body-requests'].scrollTop === 240, DOM.byId['dc-body-requests'].scrollTop);
  }

  console.log('\n== 12. an expanded group is not force-collapsed by the server ==');
  {
    const c = makeCtx({
      responder: responder(CHANGED, Object.assign({}, PANELS, {
        requests_html: '<div id="petrol-requests-body" class="hidden"><div id="petrol-req-9">NEW</div></div>'
      }))
    });
    // Operator had it OPEN; the server's fresh markup says collapsed.
    DOM.byId['petrol-requests-body'].classList.remove('hidden');
    c._poll(); await settle();
    ok('the operator\'s open group stays open',
      !DOM.byId['petrol-requests-body'].classList.contains('hidden'));
  }

  console.log('\n== 13. a failed refresh changes nothing ==');
  {
    const c = makeCtx({ responder: url =>
      url.indexOf('panels-refresh') !== -1 ? { success: false } : { success: true, counts: CHANGED } });
    c._poll(); await settle();
    ok('nothing swapped', DOM.byId['dc-body-requests'].innerHTML === '');
    ok('no flash claimed', !DOM.byId['dc-fresh-requests'].classList.contains('dc-fresh-on'));
  }

  console.log('\n== 14. a busy operator does not pile up fetches ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    DOM.byId['proofModal'].style.display = 'flex';
    c._poll(); await settle();
    c._poll(); await settle();
    c._poll(); await settle();
    ok('fetched the panels only once', hitPanels(c) === 1, hitPanels(c));
    ok('and no heartbeat spam either', c._sent.filter(u => u.indexOf('heartbeat') !== -1).length === 1);
  }

  console.log('\n== 15. returning to the tab checks immediately ==');
  {
    const c = makeCtx({ responder: responder(CHANGED) });
    ok('a visibilitychange listener is registered', typeof c._listeners.visibilitychange === 'function');
    c._listeners.visibilitychange(); await settle();
    ok('it polled on return', c._sent.length > 0);
    ok('and refreshed', DOM.byId['dc-body-messages'].innerHTML.indexOf('NEW ROW') !== -1);
  }

  console.log('\n== 16. a counter going DOWN also refreshes ==');
  {
    const c = makeCtx({ responder: responder({ deliveries: 5, proofs: 1, settled: 1, requests: 4 }) });
    c._poll(); await settle();
    ok('a withdrawn proof refreshes too', hitPanels(c) === 1);
  }

  console.log('\n' + (fail === 0 ? 'ALL ' + pass + ' CHECKS PASSED' : pass + ' passed, ' + fail + ' FAILED'));
  process.exit(fail === 0 ? 0 : 1);
})();
