const fs = require('fs'), vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');

let pass = 0, fail = 0;
const ok = (name, cond, extra) => {
  if (cond) { pass++; console.log('  PASS ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra !== undefined ? '  -> ' + JSON.stringify(extra) : '')); }
};

function mkEl(id) {
  return {
    id, style: {}, dataset: {}, _html: '', textContent: '', title: '', disabled: false,
    attributes: {}, children: [],
    get innerHTML() { return this._html; },
    set innerHTML(v) { this._html = v; },
    setAttribute(k, v) { this.attributes[k] = v; },
    getAttribute(k) { return this.attributes[k] !== undefined ? this.attributes[k] : null; },
    querySelector(sel) { return this.children.find(c => c._sel === sel) || null; },
    closest() { return { querySelector: () => ({ textContent: '', style: {} }) }; },
    addEventListener() {}, remove() {}
  };
}

function makeCtx(opts) {
  const els = {};
  ['fuBillsModal', 'fuBillsCustomer', 'fuBillsIntro', 'fuBillsList', 'fuBillsPreview',
   'fuBillsSummary', 'fuBillsSendBtn', 'fuBillsAllBtn'].forEach(id => { els[id] = mkEl(id); });

  (opts.boardRows || []).forEach(id => {
    const row = mkEl('fu-row-' + id);
    const btn = mkEl('btn-' + id);
    btn._sel = 'button[data-row]';
    row.children.push(btn);
    els['fu-row-' + id] = row;
    els['btn-' + id] = btn;
  });

  const sent = [];
  const ctx = {
    console,
    document: {
      getElementById: id => els[id] || null,
      querySelector: sel => (sel === 'meta[name="csrf-token"]'
        ? { getAttribute: () => 'csrf', content: 'csrf' } : null),
      createElement: () => mkEl('tmp'),
      body: { appendChild() {}, removeChild() {} },
      addEventListener() {}
    },
    fetch: (url, init) => {
      const body = init && init.body ? JSON.parse(init.body) : null;
      sent.push({ url, body });
      const r = opts.responder(url, body);
      return Promise.resolve({
        ok: r.status ? r.status < 400 : true,
        status: r.status || 200,
        json: () => Promise.resolve(r.json)
      });
    },
    confirm: msg => { ctx._confirms.push(msg); return opts.confirmAnswer !== false; },
    alert: msg => { ctx._alerts.push(msg); },
    setTimeout: fn => fn(),
    setInterval: () => 0,
    location: { reload() {} },
    navigator: { clipboard: { writeText: () => Promise.resolve() } }
  };
  ctx._els = els; ctx._sent = sent; ctx._confirms = []; ctx._alerts = [];
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(code, ctx);
  return ctx;
}

const ROW = {
  id: 101, order_number: 'NF-19597', customer_name: "Rabia O'Aamir", customer_phone: '03001234567',
  rider_name: 'Arslan Aslam', delivery_date: 'Sep 12, 2026', delivery_time: '02:00 PM',
  amount: 11893, template: 'payment_reminder_single', day_number: 3, proof_label: '',
  other_bills_count: 2, other_bills_open_count: 1, other_bills_open_amount: 6240,
  other_bills_proof_count: 1, combined_total: 18133,
  other_open_bills: [
    { id: 102, order_number: 'NF-19586', amount: 6240, delivery_date: 'Sep 11, 2026',
      age_days: 3, age_label: '3 days ago', in_window: false, has_proof: false, proof_label: null },
    { id: 103, order_number: 'SH-22800', amount: 9228, delivery_date: 'Sep 10, 2026',
      age_days: 4, age_label: '4 days ago', in_window: true, has_proof: true, proof_label: 'WhatsApp proof' }
  ]
};

const cleanPre = { success: true, stale: false };
const defaultResponder = url => {
  if (url.indexOf('followup-precheck') !== -1) return { json: cleanPre };
  if (url.indexOf('send-template') !== -1) return { json: { success: true } };
  return { json: { success: true, sent_at: '05:00 PM', stamped: { 101: '05:00 PM' } } };
};

const clone = o => JSON.parse(JSON.stringify(o));
const settle = async () => { for (let i = 0; i < 14; i++) await new Promise(r => setImmediate(r)); };
const tmplSends = c => c._sent.filter(s => s.url.indexOf('send-template') !== -1);
const markSends = c => c._sent.filter(s => s.url.indexOf('mark-online-message-sent') !== -1);

(async function () {
  console.log('\n== 1. modal opens with ONLY the clicked bill ticked ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    ok('modal shown', c._els.fuBillsModal.style.display === 'flex');
    ok('3 bills listed', (c._els.fuBillsList.innerHTML.match(/data-bill-id=/g) || []).length === 3);
    ok('only the clicked bill selected', JSON.stringify(c.fuBillsState.selected) === '[101]', c.fuBillsState.selected);
    ok('apostrophe in name escaped', c._els.fuBillsIntro.innerHTML.indexOf('&#39;') !== -1);
    ok('summary says 1 bill', c._els.fuBillsSummary.innerHTML.indexOf('1 bill') !== -1, c._els.fuBillsSummary.innerHTML);
    ok('send button singular', c._els.fuBillsSendBtn.textContent === '\u{1F4F1} Send reminder', c._els.fuBillsSendBtn.textContent);
    ok('proof bill labelled', c._els.fuBillsList.innerHTML.indexOf('WhatsApp proof') !== -1);
    ok('older bill labelled', c._els.fuBillsList.innerHTML.indexOf('older than this board') !== -1);
    ok('same-board bill labelled', c._els.fuBillsList.innerHTML.indexOf('also on this board') !== -1);
  }

  console.log('\n== 2. tick-all covers only CHASEABLE bills (proof one excluded) ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    ok('widen button offered', c._els.fuBillsAllBtn.textContent.indexOf('Tick all 2') !== -1, c._els.fuBillsAllBtn.textContent);
    c.fuToggleAllBills();
    ok('selected 101+102, proof bill 103 left out', JSON.stringify(c.fuBillsState.selected.slice().sort()) === '[101,102]', c.fuBillsState.selected);
    ok('total is 18,133', c._els.fuBillsSummary.innerHTML.indexOf('18,133') !== -1, c._els.fuBillsSummary.innerHTML);
    ok('preview names both invoices', c._els.fuBillsPreview.textContent.indexOf('NF-19597, NF-19586') !== -1);
    ok('preview is the multi template', c._els.fuBillsPreview.textContent.indexOf('Total pending amount: PKR 18,133') !== -1);
    ok('preview greets with FIRST name only', c._els.fuBillsPreview.textContent.indexOf('Dear Rabia,') === 0, c._els.fuBillsPreview.textContent.slice(0, 30));
    c.fuToggleAllBills();
    ok('toggles back to just this bill', JSON.stringify(c.fuBillsState.selected) === '[101]', c.fuBillsState.selected);
  }

  console.log('\n== 3. clicked-bill-only send hands back to the ORIGINAL path ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    const t = tmplSends(c);
    ok('one template send', t.length === 1, t.map(x => x.body.template_name));
    ok('used payment_reminder_single', t[0].body.template_name === 'payment_reminder_single');
    ok('carried order_id for the invoice image', t[0].body.order_id === 101);
    ok('no related_order_numbers', t[0].body.related_order_numbers === undefined);
    const m = markSends(c);
    ok('marked sent once', m.length === 1);
    ok('legacy empty body preserved', JSON.stringify(m[0].body) === '{}', m[0].body);
    ok('row greyed', c._els['btn-101'].textContent === '✓ Reminded today');
  }

  console.log('\n== 4. two bills -> multi template, no order_id, both ids stamped ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c.fuToggleAllBills();
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    const t = tmplSends(c)[0];
    ok('used payment_reminder_multiples', t.body.template_name === 'payment_reminder_multiples');
    ok('NO order_id on the headerless template', t.body.order_id === undefined, t.body);
    ok('related_order_numbers lists both', JSON.stringify(t.body.related_order_numbers) === '["NF-19597","NF-19586"]', t.body.related_order_numbers);
    ok('related_order_number is the primary', t.body.related_order_number === 'NF-19597');
    ok('body params are [first name, list, total]', JSON.stringify(t.body.body_params) === '["Rabia","NF-19597, NF-19586","18,133"]', t.body.body_params);
    const m = markSends(c)[0];
    ok('stamped BOTH orders', JSON.stringify(m.body.order_ids) === '[101,102]', m.body);
    ok('clicked row greyed', c._els['btn-101'].textContent === '✓ Reminded today');
    ok('modal closed', c._els.fuBillsModal.style.display === 'none');
  }

  console.log('\n== 5. a bill that is ALSO a row on this board goes grey too ==');
  {
    const row = clone(ROW);
    row.other_open_bills[1].has_proof = false;
    row.other_open_bills[1].proof_label = null;
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(row, c._els['btn-101']);
    await settle();
    c.fuToggleAllBills();
    ok('all three ticked', c.fuBillsState.selected.length === 3, c.fuBillsState.selected);
    c.fuSendSelectedBills();
    await settle();
    ok('the other board row greyed too', c._els['btn-103'].textContent === '✓ Reminded today', c._els['btn-103'].textContent);
    ok('off-board bill has no tile to grey', c._els['fu-row-102'] === undefined);
  }

  console.log('\n== 6. untick the clicked row, send only the OLD bill ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c.fuBillToggled({ getAttribute: () => '101', checked: false });
    c.fuBillToggled({ getAttribute: () => '102', checked: true });
    ok('only the old bill ticked', JSON.stringify(c.fuBillsState.selected) === '[102]', c.fuBillsState.selected);
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    const t = tmplSends(c)[0];
    ok('single template for the old bill', t.body.template_name === 'payment_reminder_single');
    ok('order_id is the OLD order', t.body.order_id === 102, t.body.order_id);
    ok('params name the old invoice', JSON.stringify(t.body.body_params) === JSON.stringify(["Rabia O'Aamir", 'NF-19586', '6,240']), t.body.body_params);
    const m = markSends(c)[0];
    ok('stamped only the old order', JSON.stringify(m.body.order_ids) === '[102]', m.body);
    ok('clicked row stays chaseable', c._els['btn-101'].disabled === false);
  }

  console.log('\n== 7. no invoice image (422) falls back to the multi template ==');
  {
    let first = true;
    const c = makeCtx({ boardRows: [101, 103], responder: url => {
      if (url.indexOf('followup-precheck') !== -1) return { json: cleanPre };
      if (url.indexOf('send-template') !== -1) {
        if (first) { first = false; return { status: 422, json: { success: false, message: 'no invoice image' } }; }
        return { json: { success: true } };
      }
      return { json: { success: true, stamped: { 102: '05:00 PM' } } };
    } });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c.fuBillToggled({ getAttribute: () => '101', checked: false });
    c.fuBillToggled({ getAttribute: () => '102', checked: true });
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    const t = tmplSends(c);
    ok('retried once', t.length === 2, t.length);
    ok('retry used multiples', t[1].body.template_name === 'payment_reminder_multiples');
    ok('retry carries NO order_id', t[1].body.order_id === undefined);
    ok('still stamped after the fallback', markSends(c).length === 1);
  }

  console.log('\n== 8. precheck: money already approved unticks that bill ==');
  {
    const c = makeCtx({ boardRows: [101, 103], responder: url => {
      if (url.indexOf('followup-precheck/102') !== -1) {
        return { json: { success: true, stale: true, settled: true, message: 'the payment has already been APPROVED in the ledger.' } };
      }
      if (url.indexOf('followup-precheck') !== -1) return { json: cleanPre };
      return { json: { success: true } };
    } });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    const b = c.fuBillsState.bills.find(x => x.id === 102);
    ok('102 marked settled', b.settled === true);
    ok('its checkbox is disabled', c._els.fuBillsList.innerHTML.indexOf('disabled') !== -1);
    ok('the reason is shown', c._els.fuBillsList.innerHTML.indexOf('already APPROVED') !== -1);
    c.fuToggleAllBills();
    ok('tick-all skips the settled bill', JSON.stringify(c.fuBillsState.selected) === '[101]', c.fuBillsState.selected);
  }

  console.log('\n== 9. precheck: proof landed after page load -> warned, left unticked ==');
  {
    const c = makeCtx({ boardRows: [101, 103], responder: url => {
      if (url.indexOf('followup-precheck/102') !== -1) {
        return { json: { success: true, stale: true, has_proof: true, proof_label: 'Bank SMS', message: 'payment proof has arrived (Bank SMS).' } };
      }
      if (url.indexOf('followup-precheck') !== -1) return { json: cleanPre };
      return { json: { success: true } };
    } });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    const b = c.fuBillsState.bills.find(x => x.id === 102);
    ok('102 now carries proof', b.has_proof === true && b.proof_label === 'Bank SMS');
    ok('freshness note shown', c._els.fuBillsList.innerHTML.indexOf('Proof arrived since this page loaded') !== -1);
    ok('still unticked', c.fuBillsState.selected.indexOf(102) === -1);
  }

  console.log('\n== 10. a bill that goes stale at SEND time asks first ==');
  {
    let n = 0;
    const c = makeCtx({ boardRows: [101, 103], confirmAnswer: false, responder: url => {
      if (url.indexOf('followup-precheck/102') !== -1) {
        n++;
        return { json: n > 1 ? { success: true, stale: true, message: 'payment proof has arrived (Bank SMS).' } : cleanPre };
      }
      if (url.indexOf('followup-precheck') !== -1) return { json: cleanPre };
      return { json: { success: true } };
    } });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c.fuToggleAllBills();
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    ok('operator was asked', c._confirms.length === 1, c._confirms.length);
    ok('the confirm names the bill', !!c._confirms[0] && c._confirms[0].indexOf('NF-19586') !== -1);
    ok('declining sends NOTHING', tmplSends(c).length === 0);
    ok('nothing stamped', markSends(c).length === 0);
  }

  console.log('\n== 11. send failure stamps nothing ==');
  {
    const c = makeCtx({ boardRows: [101, 103], responder: url => {
      if (url.indexOf('followup-precheck') !== -1) return { json: cleanPre };
      if (url.indexOf('send-template') !== -1) return { status: 500, json: { success: false, message: 'Meta down' } };
      return { json: { success: true } };
    } });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c.fuToggleAllBills();
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    ok('nothing stamped', markSends(c).length === 0);
    ok('operator told why', c._alerts.length === 1 && c._alerts[0].indexOf('Meta down') !== -1, c._alerts);
    ok('row still chaseable', c._els['btn-101'].disabled === false);
    ok('modal stays open to retry', c._els.fuBillsModal.style.display === 'flex');
  }

  console.log('\n== 12. cancel sends nothing and re-arms the row ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c._els['btn-101'].disabled = true;
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c._sent.length = 0;
    c.fuCloseBills();
    ok('no requests', c._sent.length === 0);
    ok('button re-armed', c._els['btn-101'].disabled === false);
    ok('state cleared', c.fuBillsState === null);
  }

  console.log('\n== 13. unticking everything disables the send ==');
  {
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.fuOpenBills(clone(ROW), c._els['btn-101']);
    await settle();
    c.fuBillToggled({ getAttribute: () => '101', checked: false });
    ok('send disabled', c._els.fuBillsSendBtn.disabled === true);
    ok('summary explains', c._els.fuBillsSummary.textContent.indexOf('no message') !== -1, c._els.fuBillsSummary.textContent);
    c._sent.length = 0;
    c.fuSendSelectedBills();
    await settle();
    ok('send is a no-op', c._sent.length === 0);
  }

  console.log('\n== 14. day-1 row alone still previews the DELIVERY CONFIRMATION ==');
  {
    const row = clone(ROW);
    row.day_number = 1;
    row.template = 'delivery_confirmation_online_v2';
    const c = makeCtx({ responder: defaultResponder, boardRows: [101, 103] });
    c.FU_BANK_ACCOUNTS = 'Meezan 1234';
    c.fuOpenBills(row, c._els['btn-101']);
    await settle();
    ok('preview is the confirmation', c._els.fuBillsPreview.textContent.indexOf('has been successfully delivered') !== -1, c._els.fuBillsPreview.textContent.slice(0, 60));
    c.fuToggleAllBills();
    ok('widening switches to the reminder', c._els.fuBillsPreview.textContent.indexOf('This is a payment reminder') !== -1);
  }

  console.log('\n' + (fail === 0 ? 'ALL ' + pass + ' CHECKS PASSED' : pass + ' passed, ' + fail + ' FAILED'));
  process.exit(fail === 0 ? 0 : 1);
})();
