
/* Storage (Supplies).
   NOTE: no Blade echo of any kind inside this block. Script content is raw text, so an
   escaped echo renders HTML entities and kills the whole block silently. Everything the
   page needs comes from data- attributes or the JSON endpoints. */
(function () {
    'use strict';

    var root = document.getElementById('supRoot');
    if (!root) { return; }

    var CAN_MANAGE = root.dataset.canManage === '1';
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var products = [];      // catalogue for the forms
    var categories = [];
    var paySources = [];
    var banks = [];
    var staged = [];        // packets being entered
    var editingProductId = null;
    var takeOut = { productId: null, mode: null, packets: [], chosen: null };

    /* ⭐⭐ ONE uuid per ATTEMPT, minted when the form opens — never per click.
       It used to be created inside supBookIn(), which defeated the whole point: when a
       Save times out the page says "nothing was recorded", but the server may well have
       committed — and the next Save carried a NEW uuid, so the purchase was booked twice
       and the money left twice. Held here and cleared only on success or Cancel, so every
       retry of the same intake is the same request as far as the server is concerned. */
    var bookInUuid = null;

    // ---------- misread guard (mirrors mobile utils/barcodeDecode.js) ----------
    /* ⚠⚠ A VALID CHECK DIGIT IS NOT PROOF OF A CORRECT READ. Paired digit flips whose
       weighted deltas cancel mod 10 pass EAN-13 cleanly, and a real 0.505 kg label once
       decoded as 9.205 kg (Aug-28-2026). The order scanner compares against the line's
       quantity; intake has no such baseline, so each read is compared against the MEDIAN
       of the packets already staged in THIS batch.

       It must be a median, not a mean: one wild read drags a mean far enough to then
       ACCEPT the next bad one, which is the exact failure this is here to stop.

       The band is deliberately wide — a false alarm interrupts a busy manager, a miss
       mis-costs every packet in the batch permanently. Same two numbers as the phone;
       change them in both places or the two surfaces start disagreeing. */
    var OUTLIER_RATIO = 4;
    var OUTLIER_MIN_GAP_KG = 1;

    function medianOf(weights) {
        var xs = (weights || []).map(function (n) { return parseFloat(n); })
            .filter(function (n) { return n > 0; })
            .sort(function (a, b) { return a - b; });
        if (!xs.length) { return 0; }               // first packet has no baseline, by design
        var mid = Math.floor(xs.length / 2);
        return xs.length % 2 ? xs[mid] : (xs[mid - 1] + xs[mid]) / 2;
    }

    function isWeightOutlier(nextQty, currentQty) {
        var next = parseFloat(nextQty), cur = parseFloat(currentQty);
        if (!(next > 0) || !(cur > 0)) { return false; }
        if (Math.abs(next - cur) < OUTLIER_MIN_GAP_KG) { return false; }
        return next >= cur * OUTLIER_RATIO || next <= cur / OUTLIER_RATIO;
    }

    // ---------- helpers ----------
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }
    function get(url) {
        return fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function money(n) { return Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 0 }); }
    function trimQty(n) {
        var s = Number(n || 0).toFixed(3);
        return s.replace(/0+$/, '').replace(/\.$/, '');
    }
    function show(id, on) {
        var el = document.getElementById(id);
        if (el) { el.classList.toggle('sup-none', !on); }
    }
    function msg(id, text, kind) {
        var el = document.getElementById(id);
        if (!el) { return; }
        el.textContent = text || '';
        el.style.display = text ? 'block' : 'none';
        el.className = 'sup-msg ' + (kind === 'ok' ? 'sup-msg-ok' : 'sup-msg-err');
    }
    window.supClose = function (id) {
        var el = document.getElementById(id);
        if (el) { el.classList.remove('sup-modal-on'); }
        // Walking away from Add stock ends that attempt — the next one is a new purchase
        // and must carry a new uuid, or it would collapse onto the abandoned one.
        if (id === 'supBookInModal') { bookInUuid = null; }
    };
    function open(id) {
        var el = document.getElementById(id);
        if (el) { el.classList.add('sup-modal-on'); }
    }
    function productById(id) {
        for (var i = 0; i < products.length; i++) {
            if (Number(products[i].id) === Number(id)) { return products[i]; }
        }
        return null;
    }

    // ---------- tabs ----------
    window.supShowTab = function (tab) {
        ['stock', 'batches', 'takeouts', 'history'].forEach(function (t) {
            show('supPane' + t.charAt(0).toUpperCase() + t.slice(1), t === tab);
            var btn = document.getElementById('supTab' + t.charAt(0).toUpperCase() + t.slice(1));
            if (btn) { btn.classList.toggle('sup-tab-on', t === tab); }
        });
        if (tab === 'batches') { supLoadBatches(); }
        if (tab === 'takeouts') { supLoadTakeouts(); }
        if (tab === 'history') { supLoadHistory(); }
    };

    /* ⭐ The take-outs list — the screen where a mistake gets fixed without anyone going
       near the Expenses page. A manager sees everyone's; a store user sees his own. */
    window.supLoadTakeouts = function () {
        var box = document.getElementById('supTakeoutList');
        box.innerHTML = '<div class="sup-empty">Loading…</div>';
        get('/supplies/my-takeouts').then(function (r) {
            var rows = (r.data && r.data.takeouts) || [];
            if (!rows.length) { box.innerHTML = '<div class="sup-empty">No take-outs yet.</div>'; return; }
            box.innerHTML =
                (r.data.shows_everyone ? '<div class="sup-hint" style="margin-bottom:8px;">Showing everyone\'s take-outs.</div>' : '') +
                '<div class="sup-tablewrap"><table class="sup-table"><thead><tr>' +
                '<th>When</th><th>Item</th><th>Out</th><th>Cost</th><th>Who</th><th>Status</th><th></th>' +
                '</tr></thead><tbody>' +
                rows.map(function (t) {
                    var gone = t.status === 'undone' || t.status === 'rejected';
                    return '<tr' + (gone ? ' style="opacity:.55;"' : '') + '>' +
                        '<td>' + esc(t.at || '') + '</td>' +
                        '<td>' + esc(t.product_name || '') +
                            (t.typed ? ' <span class="sup-pmeta">(typed)</span>' : '') + '</td>' +
                        '<td>' + esc(t.qty_label || (trimQty(t.qty) + ' ' + (t.unit || ''))) + '</td>' +
                        '<td>' + (t.cost > 0 ? 'Rs ' + money(t.cost) : '—') + '</td>' +
                        '<td>' + esc(t.taken_by_name || (t.mine ? 'you' : '')) + '</td>' +
                        '<td>' + esc(t.status) + (t.request_number ? '<div class="sup-pmeta">' + esc(t.request_number) + '</div>' : '') + '</td>' +
                        '<td style="white-space:nowrap;">' +
                          (t.can_edit ? '<button class="sup-btn" onclick="supOpenEditTakeout(' + t.id + ',' + Number(t.qty) + ',\'' + esc(t.product_name) + '\')">Edit weight</button> ' : '') +
                          (t.can_delete ? '<button class="sup-btn sup-btn-danger" onclick="supDeleteTakeout(' + t.id + ',\'' + esc(t.qty_label || '') + '\')">Delete</button>' : '') +
                        '</td></tr>';
                }).join('') + '</tbody></table></div>';
        });
    };

    window.supDeleteTakeout = function (id, label) {
        if (!window.confirm('Delete this take-out?\n\n' + label + ' goes back into Storage, and if it was ' +
                            'already booked to expenses that entry is reversed.\n\nThis is recorded with your name.')) { return; }
        post('/supplies/take-out/' + id + '/delete', { reason: 'Deleted from the Storage page' }).then(function (r) {
            if (!r.ok || !r.data.success) { window.alert((r.data && r.data.message) || 'Could not delete it.'); return; }
            window.alert(r.data.message);
            window.location.reload();
        }).catch(function () { window.alert('Could not reach the server. Nothing was changed.'); });
    };

    /* ⭐ Editing a weight moves money, so the figure is previewed BEFORE anything happens —
       and if it would restate a month already reported, it says so. */
    var editingTakeout = null;
    window.supOpenEditTakeout = function (id, qty, name) {
        editingTakeout = id;
        msg('supEdMsg', '');
        document.getElementById('supEdTitle').textContent = 'Correct the weight — ' + name;
        document.getElementById('supEdQty').value = qty;
        document.getElementById('supEdReason').value = '';
        document.getElementById('supEdPreview').innerHTML = 'Change the figure and press <b>Show me what changes</b>.';
        document.getElementById('supEdApply').classList.add('sup-none');
        open('supEditModal');
    };

    window.supPreviewEditTakeout = function () {
        var qty = parseFloat(document.getElementById('supEdQty').value || '0');
        if (!(qty > 0)) { msg('supEdMsg', 'Enter a quantity above zero — use Delete to reverse it entirely.'); return; }
        msg('supEdMsg', '');
        post('/supplies/take-out/' + editingTakeout + '/preview-edit', { qty: qty }).then(function (r) {
            var d = r.data || {};
            if (!r.ok || !d.success) { msg('supEdMsg', d.message || 'Could not work that out.'); return; }
            document.getElementById('supEdPreview').innerHTML =
                '<div><b>' + esc(d.was_qty_label) + '</b> · Rs ' + money(d.was_cost) +
                '  →  <b>' + esc(d.qty_label) + '</b> · Rs ' + money(d.cost) + '</div>' +
                '<div class="sup-pmeta" style="margin-top:6px;">' +
                  (d.legs || []).map(function (l) {
                      return 'from the purchase of ' + esc(l.purchase_date) + ': ' + trimQty(l.qty) + ' · Rs ' + money(l.cost);
                  }).join('<br>') + '</div>' +
                (d.restates_earlier_month
                    ? '<div style="margin-top:8px;color:#B45309;"><b>Careful:</b> this expense is dated ' +
                      esc(d.expense_month) + ', a month that has already been reported. Correcting it changes ' +
                      'that month\'s packaging cost — which is the honest figure, but it will move.</div>'
                    : '');
            document.getElementById('supEdApply').classList.remove('sup-none');
        });
    };

    window.supApplyEditTakeout = function () {
        var qty = parseFloat(document.getElementById('supEdQty').value || '0');
        var btn = document.getElementById('supEdApply');
        btn.disabled = true;
        post('/supplies/take-out/' + editingTakeout + '/edit', {
            qty: qty, reason: document.getElementById('supEdReason').value || null
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) { msg('supEdMsg', (r.data && r.data.message) || 'Could not save.'); return; }
            window.alert(r.data.message);
            window.location.reload();
        }).catch(function () { btn.disabled = false; msg('supEdMsg', 'Could not reach the server. Nothing was changed.'); });
    };

    // ---------- catalogue ----------
    function loadCatalogue() {
        return get('/supplies/products').then(function (r) {
            if (!r.ok || !r.data.success) { return; }
            products = r.data.products || [];
            categories = r.data.expense_categories || [];
            paySources = r.data.payment_sources || [];
            banks = r.data.banks || [];
        });
    }

    // ---------- purchases ----------
    window.supShowBatches = function (productId) {
        supShowTab('batches');
        var sel = document.getElementById('supBatchProduct');
        if (sel) { sel.value = String(productId); }
        supLoadBatches();
    };

    window.supLoadBatches = function () {
        var sel = document.getElementById('supBatchProduct');
        var box = document.getElementById('supBatchList');
        if (!sel || !sel.value || !box) { return; }
        box.innerHTML = '<div class="sup-empty">Loading…</div>';

        get('/supplies/' + encodeURIComponent(sel.value) + '/batches').then(function (r) {
            if (!r.ok || !r.data.success) { box.innerHTML = '<div class="sup-empty">Could not load.</div>'; return; }
            var rows = r.data.batches || [];
            if (!rows.length) { box.innerHTML = '<div class="sup-empty">No purchases recorded yet.</div>'; return; }

            var html = '<div class="sup-tablewrap"><table class="sup-table"><thead><tr>' +
                '<th>Date</th><th>Bought</th><th>Used</th><th>Left</th><th>Cost</th><th>Paid from</th><th>Status</th><th></th>' +
                '</tr></thead><tbody>';

            rows.forEach(function (b) {
                var status = b.status === 'stock_only' ? 'no charge'
                           : (b.status === 'voided' ? 'voided' : 'ok');
                html += '<tr>' +
                    '<td>' + esc(b.purchase_date || '') + '</td>' +
                    '<td>' + esc(trimQty(b.qty_total)) + (b.packet_count ? ' · ' + b.packet_count + ' packet(s)' : '') + '</td>' +
                    '<td>' + esc(trimQty(b.qty_used)) +
                        (Number(b.cost_used) > 0 ? '<div class="sup-pmeta">Rs ' + money(b.cost_used) + '</div>' : '') + '</td>' +
                    '<td>' + esc(b.qty_label || trimQty(b.qty_remaining)) +
                        (Number(b.cost_remaining) > 0 ? '<div class="sup-pmeta">Rs ' + money(b.cost_remaining) + '</div>' : '') + '</td>' +
                    '<td class="sup-money">Rs ' + money(b.total_cost) + '</td>' +
                    '<td>' + esc(b.paid_from || '—') + '</td>' +
                    '<td>' + esc(status) + '</td>' +
                    '<td style="white-space:nowrap;">' +
                        (b.can_correct ? '<button class="sup-btn" onclick="supOpenCorrect(' + Number(b.id) + ',' + Number(b.total_cost) + ')">Fix price</button> ' : '') +
                        (b.can_void
                            ? '<button class="sup-btn sup-btn-danger" onclick="supVoidBatch(' + Number(b.id) + ')">Void</button>'
                            // ⭐ Not a dead button — say WHY it cannot be voided and what to do
                            // instead. A purchase that has been drawn on has to have its
                            // take-outs deleted first (Take-outs tab), or its price fixed.
                            : (Number(b.takeouts_from_it) > 0
                                ? '<span class="sup-pmeta">' + Number(b.takeouts_from_it) +
                                  ' take-out(s) came out of this — delete those first, or use Fix price</span>'
                                : '')) +
                    '</td>' +
                    '</tr>';
            });
            box.innerHTML = html + '</tbody></table></div>';
        });
    };

    window.supVoidBatch = function (batchId) {
        if (!window.confirm('Void this purchase? The payment is reversed and the packets are removed. Only possible while none of it has been used.')) { return; }
        post('/supplies/batches/' + batchId + '/void', {}).then(function (r) {
            window.alert((r.data && r.data.message) || (r.ok ? 'Voided.' : 'Could not void.'));
            if (r.ok && r.data.success) { window.location.reload(); }
        });
    };

    // ---------- history ----------
    window.supLoadHistory = function () {
        var box = document.getElementById('supHistoryList');
        if (!box) { return; }
        box.innerHTML = '<div class="sup-empty">Loading…</div>';

        get('/supplies/history?limit=150').then(function (r) {
            if (!r.ok || !r.data.success) { box.innerHTML = '<div class="sup-empty">Could not load.</div>'; return; }
            var rows = r.data.history || [];
            if (!rows.length) { box.innerHTML = '<div class="sup-empty">Nothing yet.</div>'; return; }

            var label = { 'in': 'added', 'out': 'taken out', 'undo': 'put back', 'void': 'voided' };
            var html = '<div class="sup-tablewrap"><table class="sup-table"><thead><tr>' +
                '<th>When</th><th>What</th><th>Item</th><th>Qty</th><th>Value</th><th>By</th>' +
                '</tr></thead><tbody>';
            rows.forEach(function (h) {
                html += '<tr>' +
                    '<td>' + esc(h.at || '') + '</td>' +
                    '<td>' + esc(label[h.action] || h.action) + '</td>' +
                    '<td>' + esc(h.product_name || '') + '</td>' +
                    '<td>' + esc(trimQty(h.qty)) + ' ' + esc(h.unit || '') + '</td>' +
                    '<td class="sup-money">' + (Number(h.cost) > 0 ? 'Rs ' + money(h.cost) : '—') + '</td>' +
                    '<td>' + esc(h.by || '') + '</td>' +
                    '</tr>';
            });
            box.innerHTML = html + '</tbody></table></div>';
        });
    };

    // ---------- book in ----------
    window.supOpenBookIn = function () {
        if (!CAN_MANAGE) { return; }
        staged = [];
        msg('supBookInMsg', '');
        // One uuid for this whole intake attempt — see the note where bookInUuid is declared.
        bookInUuid = 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
        loadCatalogue().then(function () {
            var sel = document.getElementById('supBiProduct');
            sel.innerHTML = products.filter(function (p) { return Number(p.is_active) === 1 || p.is_active === true; })
                .map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');

            // ⚠ Read `is_online` / `display_name` / `is_default` / `preferred_bank_id` —
            // the fields PaymentSourceService actually returns and every other picker in
            // the app already uses. (It does NOT return account_category; keying off that
            // silently left the bank field hidden, so an online purchase could never be
            // completed — the server would keep asking which bank it left from.)
            var src = document.getElementById('supBiSource');
            src.innerHTML = paySources.map(function (a) {
                return '<option value="' + a.id +
                    '" data-online="' + (a.is_online ? '1' : '0') +
                    '" data-bank="' + esc(a.preferred_bank_id || '') + '"' +
                    (a.is_default ? ' selected' : '') + '>' +
                    esc(a.display_name || a.account_name) + '</option>';
            }).join('');

            var bank = document.getElementById('supBiBank');
            bank.innerHTML = '<option value="">— choose —</option>' + banks.map(function (b) {
                return '<option value="' + b.id + '">' + esc(b.short_code || b.bank_name || b.name || ('#' + b.id)) + '</option>';
            }).join('');

            document.getElementById('supBiDate').value = new Date().toISOString().slice(0, 10);
            document.getElementById('supBiCost').value = '';
            document.getElementById('supBiPieces').value = '';
            document.getElementById('supBiNote').value = '';
            document.getElementById('supBiExpected').value = '';
            document.getElementById('supBiStockOnly').checked = false;

            supBiProductChanged();
            supBiSourceChanged();
            supBiStockOnlyChanged();
            renderStaged();
            open('supBookInModal');
            setTimeout(function () {
                var i = document.getElementById('supBiScanInput');
                if (i && !i.closest('.sup-none')) { i.focus(); }
            }, 120);
        });
    };

    window.supBiProductChanged = function () {
        var p = productById(document.getElementById('supBiProduct').value);
        staged = [];
        renderStaged();
        if (!p) { return; }
        var isPieces = p.mode === 'pieces';
        show('supBiScanBlock', !isPieces);
        show('supBiPiecesBlock', isPieces);

        var label = document.getElementById('supBiScanLabel');
        var hint = document.getElementById('supBiScanHint');
        var manual = document.getElementById('supBiManualBtn');
        var expLabel = document.getElementById('supBiExpectedLabel');
        var expHint = document.getElementById('supBiExpectedHint');
        var expInput = document.getElementById('supBiExpected');
        expInput.value = '';

        if (p.mode === 'weight') {
            label.textContent = 'Scan each packet';
            hint.textContent = 'Every scale label is one packet. Two packets of the same weight print the same label — that is fine, scan both.';
            manual.textContent = '＋ Add without scanning';
            expLabel.textContent = 'Total weight on the bill, kg (optional)';
            expInput.step = '0.001';
            expHint.textContent = 'If you know what the whole lot weighs, type it here and we will check the scanned packets add up to it.';
        } else if (p.mode === 'scan') {
            label.textContent = 'Scan each packet';
            hint.textContent = 'One scan = one packet.';
            manual.textContent = '＋ Add one packet';
            expLabel.textContent = 'How many packets on the bill (optional)';
            expInput.step = '1';
            expHint.textContent = 'If you know how many packets came, type it here and we will check the scans match.';
        }
    };

    window.supBiSourceChanged = function () {
        var sel = document.getElementById('supBiSource');
        var opt = sel.options[sel.selectedIndex];
        var isBank = !!(opt && opt.dataset.online === '1');
        show('supBiBankField', isBank && !document.getElementById('supBiStockOnly').checked);

        // Preselect the bank this account normally pays from, the same courtesy the
        // other pickers give — the person can still change it.
        if (isBank && opt.dataset.bank) {
            var bank = document.getElementById('supBiBank');
            if (bank && !bank.value) { bank.value = opt.dataset.bank; }
        }
    };

    window.supBiStockOnlyChanged = function () {
        var on = document.getElementById('supBiStockOnly').checked;
        document.getElementById('supBiCost').disabled = on;
        document.getElementById('supBiSource').disabled = on;
        if (on) { document.getElementById('supBiCost').value = ''; }
        supBiSourceChanged();
    };

    window.supBiScanKey = function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.preventDefault();
        var input = ev.target;
        var raw = (input.value || '').trim();
        input.value = '';
        if (!raw) { return; }

        var p = productById(document.getElementById('supBiProduct').value);
        if (!p) { return; }

        if (p.mode === 'scan') {
            if (p.barcode && raw !== String(p.barcode)) {
                msg('supBookInMsg', 'That barcode is not ' + p.name + '.');
                return;
            }
            msg('supBookInMsg', '');
            staged.push({ qty: 1, barcode: p.barcode, source: 'scan' });
            renderStaged();
            return;
        }

        // weight: the SERVER decodes, so the page never re-implements the EAN maths
        post('/supplies/decode', { barcode: raw }).then(function (r) {
            if (!r.ok || !r.data.success) {
                msg('supBookInMsg', (r.data && r.data.message) || 'Could not read that label.');
                return;
            }
            if (Number(r.data.plu) !== Number(p.plu)) {
                msg('supBookInMsg', 'That label is PLU ' + r.data.plu + ', not ' + p.name + '.');
                return;
            }

            // ⭐ The guard. Compare against the median of what is already in this batch;
            // the first packet has nothing to compare with and is never questioned.
            var baseline = medianOf(staged.map(function (s) { return Number(s.qty) || 0; }));
            if (baseline > 0 && isWeightOutlier(r.data.weight_kg, baseline)) {
                // ⚠ With only ONE packet staged the baseline is that single packet, so we
                // genuinely cannot tell which of the two misread — and if the FIRST scan
                // was the bad one it becomes the baseline and every good packet after it
                // gets questioned. Say so plainly and point at the ✕, instead of implying
                // the new read is the guilty one.
                var comparison = staged.length === 1
                    ? 'The only other packet in this batch is ' + trimQty(baseline) + ' kg, so one of the '
                      + 'two misread — check both labels, and use ✕ to remove whichever is wrong.'
                    : 'The other packets in this batch are around ' + trimQty(baseline) + ' kg.';

                var keep = window.confirm(
                    'Unusual weight — ' + trimQty(r.data.weight_kg) + ' kg.\n\n' +
                    comparison + '\n' +
                    'A label can misread and still look valid, and a wrong weight here re-prices ' +
                    'EVERY packet in this batch.\n\n' +
                    'OK = the weight is correct, add it.\nCancel = scan the label again.');
                if (!keep) {
                    msg('supBookInMsg', 'Not added — scan that label again.');
                    return;
                }
            }

            msg('supBookInMsg', '');
            staged.push({ qty: r.data.weight_kg, barcode: r.data.barcode, source: 'scan' });
            renderStaged();
        });
    };

    window.supBiAddManual = function () {
        var p = productById(document.getElementById('supBiProduct').value);
        if (!p) { return; }
        if (p.mode === 'scan') {
            staged.push({ qty: 1, barcode: p.barcode, source: 'manual' });
            renderStaged();
            return;
        }
        var kg = window.prompt('Weight of this packet in kg (e.g. 1.25)');
        if (kg === null) { return; }
        var n = parseFloat(kg);
        if (!(n > 0)) { msg('supBookInMsg', 'Enter a weight greater than zero.'); return; }
        staged.push({ qty: n, source: 'manual' });
        renderStaged();
    };

    window.supBiRemove = function (i) { staged.splice(i, 1); renderStaged(); };

    function renderStaged() {
        var box = document.getElementById('supBiStaged');
        var p = productById((document.getElementById('supBiProduct') || {}).value);
        if (!box) { return; }
        if (!staged.length) {
            box.innerHTML = '<div class="sup-staged-row" style="color:#9CA3AF;">Nothing scanned yet.</div>';
        } else {
            box.innerHTML = staged.map(function (s, i) {
                var q = (p && p.mode === 'weight') ? trimQty(s.qty) + ' kg' : '1 packet';
                return '<div class="sup-staged-row"><span>' + (i + 1) + '. ' + esc(q) +
                    (s.source === 'manual' ? ' <span class="sup-pmeta">(typed)</span>' : '') +
                    '</span><button class="sup-x" onclick="supBiRemove(' + i + ')">✕</button></div>';
            }).join('');
        }
        var total = staged.reduce(function (a, s) { return a + Number(s.qty || 0); }, 0);
        document.getElementById('supBiCountLabel').textContent = staged.length + ' packet(s)';
        document.getElementById('supBiQtyLabel').textContent =
            (p && p.mode === 'weight') ? trimQty(total) + ' kg' : staged.length + ' packet(s)';
    }

    /* Does the scanned batch add up to what the bill says? Returns true to carry on.
       Blank = skip (the bill is not always to hand). A weight lot is allowed 0.5 % or
       5 g of slack, whichever is larger — scale rounding, not a missing packet. A packet
       count must match exactly: you cannot be half a packet out. */
    function supBiExpectedOk(p) {
        var raw = (document.getElementById('supBiExpected').value || '').trim();
        if (raw === '') { return true; }
        var expected = parseFloat(raw);
        if (!(expected > 0)) { return true; }

        var byWeight = p.mode === 'weight';
        var actual = byWeight
            ? staged.reduce(function (a, s) { return a + (Number(s.qty) || 0); }, 0)
            : staged.length;
        var tolerance = byWeight ? Math.max(expected * 0.005, 0.005) : 0;

        if (Math.abs(actual - expected) <= tolerance) { return true; }

        var unit = byWeight ? ' kg' : ' packet(s)';
        var shown = byWeight ? trimQty(actual) : String(actual);
        return window.confirm(
            'That does not add up.\n\n' +
            'Scanned: ' + shown + unit + ' in ' + staged.length + ' packet(s)\n' +
            'On the bill: ' + trimQty(expected) + unit + '\n\n' +
            (actual < expected
                ? 'A packet may be missing, or one label did not read.'
                : 'A label may have been scanned twice — two packets of the same weight print the same label.') +
            '\n\nThe price is divided across the total, so if this is wrong EVERY packet ' +
            'in this batch is priced wrong.\n\nOK = save it anyway.\nCancel = go back and fix it.');
    }

    window.supBookIn = function () {
        var p = productById(document.getElementById('supBiProduct').value);
        if (!p) { return; }
        var stockOnly = document.getElementById('supBiStockOnly').checked;
        // ⚠ REUSE the uuid minted when the form opened. Generating one here meant a retry
        // after a timeout was a brand-new purchase to the server. Never regenerate it on
        // a failure path — that failure is exactly when it matters.
        if (!bookInUuid) { bookInUuid = 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10); }

        var body = {
            product_id: p.id,
            purchase_date: document.getElementById('supBiDate').value || null,
            total_cost: stockOnly ? 0 : parseFloat(document.getElementById('supBiCost').value || '0'),
            stock_only: stockOnly,
            note: document.getElementById('supBiNote').value || null,
            client_uuid: bookInUuid
        };

        if (p.mode === 'pieces') {
            body.pieces_qty = parseFloat(document.getElementById('supBiPieces').value || '0');
            if (!(body.pieces_qty > 0)) { msg('supBookInMsg', 'How many pieces are you adding?'); return; }
        } else {
            if (!staged.length) { msg('supBookInMsg', 'Scan at least one packet.'); return; }
            body.packets = staged;

            // ⭐ The total cross-check. A missed packet and a double-scanned label both
            // look perfectly normal packet-by-packet, and both re-price the whole batch.
            if (!supBiExpectedOk(p)) { return; }
        }

        if (!stockOnly) {
            if (!(body.total_cost > 0)) { msg('supBookInMsg', 'Enter what was paid for this stock.'); return; }
            body.payment_source_account_id = parseInt(document.getElementById('supBiSource').value, 10) || null;
            if (!body.payment_source_account_id) { msg('supBookInMsg', 'Choose which account paid.'); return; }
            var bankField = document.getElementById('supBiBankField');
            if (!bankField.classList.contains('sup-none')) {
                body.receiving_account_id = parseInt(document.getElementById('supBiBank').value, 10) || null;
                if (!body.receiving_account_id) { msg('supBookInMsg', 'Select which bank this payment came from.'); return; }
            }
        }

        var btn = document.getElementById('supBiSave');
        btn.disabled = true;
        post('/supplies/batches', body).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supBookInMsg', (r.data && r.data.message) || 'Could not save. Nothing was recorded.');
                return;
            }
            bookInUuid = null;               // this attempt is finished
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            // ⚠ Honest copy. The old line claimed "nothing was recorded", which we cannot
            // know — the request may have committed and the reply been lost. The uuid is
            // deliberately KEPT, so pressing Save again lands on the same batch instead of
            // booking a second one.
            msg('supBookInMsg', 'Could not reach the server. Press Save again — the same '
                + 'purchase is never booked twice.');
        });
    };

    // ---------- take out ----------
    /* ⭐ What the pool is made of. The owner keeps buying before the shelf runs out, so a
       weighed product is normally two or three purchases at once — and FIFO means the
       OLDEST is what the next packet will be priced from. Showing which one, and at what
       rate, is the difference between "trust it" and "see it". */
    window.supTogglePool = function (productId, btn) {
        var box = document.getElementById('supPool' + productId);
        if (!box) { return; }
        var open = !box.classList.contains('sup-none');
        if (open) {
            box.classList.add('sup-none');
            btn.textContent = btn.textContent.replace('▴', '▾');
            return;
        }
        box.classList.remove('sup-none');
        btn.textContent = btn.textContent.replace('▾', '▴');
        box.innerHTML = '<div class="sup-pool-row">Loading…</div>';

        get('/supplies/' + encodeURIComponent(productId) + '/batches').then(function (r) {
            var open = ((r.data && r.data.batches) || []).filter(function (b) { return b.is_active; });
            if (!open.length) { box.innerHTML = '<div class="sup-pool-row">Nothing on the shelf.</div>'; return; }
            // oldest first — that is the order they will be drawn down in
            open.sort(function (a, b) { return String(a.purchase_date).localeCompare(String(b.purchase_date)) || a.id - b.id; });
            box.innerHTML = open.map(function (b, i) {
                var rate = b.qty_total > 0 ? (b.total_cost / b.qty_total) : 0;
                return '<div class="sup-pool-row' + (i === 0 ? ' sup-pool-next' : '') + '">' +
                    '<span>' + (i === 0 ? '→ ' : '') + esc(b.qty_label) + ' left of ' + trimQty(b.qty_total) +
                    ' · bought ' + esc(String(b.purchase_date || '').slice(0, 10)) +
                    (b.paid_from ? ' · ' + esc(b.paid_from) : '') + '</span>' +
                    '<span>Rs ' + money(b.cost_remaining) + ' @ Rs ' + money(rate) + '/unit</span></div>';
            }).join('') +
            '<div class="sup-pool-row" style="color:#9CA3AF;">The next take-out comes off the one marked →.</div>';
        });
    };

    window.supOpenTakeOut = function (productId) {
        msg('supToMsg', '');
        takeOut = { productId: productId, mode: null, packets: [], chosen: null };
        var body = document.getElementById('supToBody');
        body.innerHTML = '<div class="sup-empty">Loading…</div>';
        open('supTakeOutModal');

        loadCatalogue().then(function () {
            var p = productById(productId);
            if (!p) { body.innerHTML = '<div class="sup-empty">Product not found.</div>'; return; }
            takeOut.mode = p.mode;
            takeOut.pooled = (p.mode === 'weight' || p.mode === 'pieces');
            document.getElementById('supToTitle').textContent = 'Take out — ' + p.name;

            // ⭐⭐ A POOLED product is taken out by QUANTITY, not by choosing a packet.
            // The bale was booked under one tray label; what leaves is the small packet in
            // your hand. Scan it (the server reads the weight off the label) or type it —
            // a torn label must never stop the store.
            if (takeOut.pooled) {
                var isWeight = p.mode === 'weight';
                body.innerHTML =
                    '<div class="sup-field">' +
                      '<label class="sup-label">Scan the packet</label>' +
                      '<input type="text" class="sup-input" id="supToScan" autocomplete="off"' +
                        ' placeholder="Scan the label, or type the ' + (isWeight ? 'weight' : 'count') + ' below"' +
                        ' onkeydown="supToScanKey(event)">' +
                    '</div>' +
                    '<div class="sup-field">' +
                      '<label class="sup-label">' + (isWeight ? 'Weight (kg)' : 'How many?') + '</label>' +
                      '<input type="number" class="sup-input" id="supToQty" min="0.001" step="' +
                        (isWeight ? '0.001' : '1') + '" oninput="supToQuoteSoon()">' +
                      '<div class="sup-hint" id="supToQuote">It comes off the oldest purchase first, so the cost is what was actually paid for it.</div>' +
                    '</div>' +
                    '<div class="sup-msg sup-msg-err sup-none" id="supToWarn"></div>';
                document.getElementById('supToConfirm').disabled = false;
                takeOut.confirmed = [];
                takeOut.scanned = null;
                setTimeout(function () {
                    var i = document.getElementById('supToScan');
                    if (i) { i.focus(); }
                }, 120);
                return;
            }

            get('/supplies/' + encodeURIComponent(productId) + '/packets').then(function (r) {
                var packets = (r.data && r.data.packets) || [];
                takeOut.packets = packets;
                if (!packets.length) {
                    body.innerHTML = '<div class="sup-empty">Nothing left in Storage. Ask Taimur or Shabib to book the new stock.</div>';
                    document.getElementById('supToConfirm').disabled = true;
                    return;
                }
                document.getElementById('supToConfirm').disabled = false;
                body.innerHTML =
                    '<div class="sup-field"><label class="sup-label">Which packet?</label>' +
                    '<select class="sup-select" id="supToPacket">' +
                    packets.map(function (pk, i) {
                        return '<option value="' + pk.id + '">' + esc(pk.qty_label) +
                            ' — Rs ' + money(pk.cost) + (pk.batch_date ? ' (bought ' + esc(pk.batch_date) + ')' : '') +
                            (i === 0 ? ' · oldest' : '') + '</option>';
                    }).join('') +
                    '</select>' +
                    '<div class="sup-hint">The oldest packet is picked first. Scanning on the phone chooses it for you.</div></div>';
            });
        });
    };

    /* The scan box on a pooled take-out. The SERVER reads the weight off the label — the
       page never re-implements the EAN maths, exactly as the intake box already works. */
    window.supToScanKey = function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.preventDefault();
        var input = ev.target;
        var raw = (input.value || '').trim();
        input.value = '';
        if (!raw) { return; }

        post('/supplies/resolve-scan', { barcode: raw }).then(function (r) {
            var d = r.data || {};
            if (!r.ok || !d.success) {
                msg('supToMsg', d.message || 'Could not read that label.');
                return;
            }
            if (Number(d.product && d.product.id) !== Number(takeOut.productId)) {
                msg('supToMsg', 'That label is ' + esc((d.product || {}).name || 'another item') + '.');
                return;
            }
            msg('supToMsg', '');
            takeOut.scanned = d.scanned_barcode || raw;
            document.getElementById('supToQty').value = d.qty;
            supToShowQuote(d);
        });
    };

    /* Price what has been typed, so the figure is on screen BEFORE the button is pressed.
       Debounced — a person typing "1.25" would otherwise fire three requests. */
    var quoteTimer = null;
    window.supToQuoteSoon = function () {
        takeOut.scanned = null;                       // typed now, not scanned
        takeOut.confirmed = [];
        if (quoteTimer) { clearTimeout(quoteTimer); }
        quoteTimer = setTimeout(function () {
            var qty = parseFloat((document.getElementById('supToQty') || {}).value || '0');
            if (!(qty > 0)) { return; }
            post('/supplies/take-out/quote', { product_id: takeOut.productId, qty: qty }).then(function (r) {
                if (r.ok && r.data && r.data.success) { supToShowQuote(r.data); }
                else { msg('supToMsg', (r.data && r.data.message) || ''); }
            });
        }, 350);
    };

    function supToShowQuote(d) {
        var hint = document.getElementById('supToQuote');
        if (hint) {
            hint.innerHTML = '<b>' + esc(d.qty_label) + '</b> — ' +
                (d.free ? 'no charge (already expensed)' : 'Rs ' + money(d.cost)) +
                ' · of ' + esc(d.pool_label) + ' on the shelf' +
                (d.capped ? ' <span style="color:#B45309;">(that is all that is left)</span>' : '');
        }
        var warn = document.getElementById('supToWarn');
        takeOut.warnings = d.warnings || [];
        if (warn) {
            if (takeOut.warnings.length) {
                warn.classList.remove('sup-none');
                warn.innerHTML = takeOut.warnings.map(function (w) {
                    return '<div><b>' + esc(w.title) + ':</b> ' + esc(w.message) + '</div>';
                }).join('') + '<div style="margin-top:4px;">Press <b>Take out</b> again to confirm.</div>';
            } else {
                warn.classList.add('sup-none');
                warn.innerHTML = '';
            }
        }
    }

    window.supTakeOut = function () {
        var body = { product_id: takeOut.productId, source: 'manual' };
        if (takeOut.pooled) {
            body.qty = parseFloat((document.getElementById('supToQty') || {}).value || '0');
            if (!(body.qty > 0)) { msg('supToMsg', 'How much are you taking out?'); return; }
            if (takeOut.scanned) { body.scanned_barcode = takeOut.scanned; body.source = 'scan'; }
            // ⚠ Confirmations are sent back BY CODE, and the server re-runs every guard
            // against them. Pressing the button a second time is the deliberate act.
            body.confirmed_warnings = (takeOut.warnings || []).map(function (w) { return w.code; });
        } else {
            var sel = document.getElementById('supToPacket');
            if (!sel || !sel.value) { msg('supToMsg', 'Nothing to take out.'); return; }
            body.packet_id = parseInt(sel.value, 10);
        }

        var btn = document.getElementById('supToConfirm');
        btn.disabled = true;
        post('/supplies/take-out', body).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                // The server raised a guard the page had not shown yet (it re-checks
                // everything, and a stale quote can miss one). Show it and let the next
                // press confirm — never write behind a warning nobody has seen.
                if (r.data && r.data.code === 'needs_confirmation') {
                    supToShowQuote({
                        qty_label: document.getElementById('supToQty').value,
                        cost: 0, free: false, pool_label: '', warnings: r.data.warnings || []
                    });
                    msg('supToMsg', '');
                    return;
                }
                msg('supToMsg', (r.data && r.data.message) || 'Could not take it out.');
                return;
            }
            window.alert(r.data.message || 'Taken out.');
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supToMsg', 'Could not reach the server. Nothing was recorded.');
        });
    };

    // ---------- product form ----------
    window.supOpenProduct = function (id) {
        if (!CAN_MANAGE) { return; }
        msg('supPrMsg', '');
        editingProductId = id || null;
        loadCatalogue().then(function () {
            var cat = document.getElementById('supPrCategory');
            cat.innerHTML = categories.map(function (c) {
                return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
            }).join('');

            var p = id ? productById(id) : null;
            document.getElementById('supPrTitle').textContent = p ? ('Edit ' + p.name) : 'New storage product';
            document.getElementById('supPrName').value = p ? p.name : '';
            document.getElementById('supPrMode').value = p ? p.mode : 'weight';
            document.getElementById('supPrPlu').value = p && p.plu ? p.plu : '';
            document.getElementById('supPrBarcode').value = p && p.barcode ? p.barcode : '';
            document.getElementById('supPrPacketBarcode').value = p && p.packet_barcode ? p.packet_barcode : '';
            document.getElementById('supPrPacketKg').value = p && p.packet_kg ? p.packet_kg : '';
            document.getElementById('supPrPiecesPer').value = p && p.pieces_per_packet ? p.pieces_per_packet : '';
            document.getElementById('supPrLow').value = p && p.low_stock_qty ? p.low_stock_qty : '';
            document.getElementById('supPrActive').value = (p && !(Number(p.is_active) === 1 || p.is_active === true)) ? '0' : '1';
            if (p && p.expense_config_id) { cat.value = String(p.expense_config_id); }

            // ⭐ Mode, PLU and barcode are FROZEN once the product has stock — changing
            // any of them re-reads the packets already on the shelf in a different unit
            // or under a code that no longer finds them. The server refuses it too
            // (SupplyStorageController::saveProduct); this is so the manager sees why
            // before filling the form instead of bouncing off a 422 afterwards.
            var locked = !!(p && p.has_stock);
            var lockHint = document.getElementById('supPrLockedHint');
            ['supPrMode', 'supPrPlu', 'supPrBarcode'].forEach(function (f) {
                var el = document.getElementById(f);
                if (el) { el.disabled = locked; }
            });
            show('supPrLockedHint', locked);
            if (locked) {
                lockHint.textContent = p.name + ' already has stock booked in, so how it is counted, '
                    + 'its PLU and its barcode are locked — the packets on the shelf were recorded that way. '
                    + 'Everything else can still be changed. For different packaging, add it as a new product.';
            }

            supPrModeChanged();
            open('supProductModal');
        });
    };

    window.supPrModeChanged = function () {
        var m = document.getElementById('supPrMode').value;
        show('supPrPluField', m === 'weight');
        show('supPrBarcodeField', m === 'scan');
        show('supPrPacketField', m === 'weight');
        show('supPrPiecesField', m === 'pieces');
    };

    window.supSaveProduct = function () {
        var body = {
            name: document.getElementById('supPrName').value.trim(),
            mode: document.getElementById('supPrMode').value,
            plu: parseInt(document.getElementById('supPrPlu').value, 10) || null,
            barcode: document.getElementById('supPrBarcode').value.trim() || null,
            // Additive, so the round-2 "frozen once it has stock" lock deliberately allows
            // these two to be filled in later — nothing already on the shelf is re-read.
            packet_barcode: document.getElementById('supPrPacketBarcode').value.trim() || null,
            packet_kg: parseFloat(document.getElementById('supPrPacketKg').value) || null,
            pieces_per_packet: parseInt(document.getElementById('supPrPiecesPer').value, 10) || null,
            expense_config_id: parseInt(document.getElementById('supPrCategory').value, 10) || null,
            low_stock_qty: parseFloat(document.getElementById('supPrLow').value) || null,
            is_active: document.getElementById('supPrActive').value === '1'
        };
        if (!body.name) { msg('supPrMsg', 'Give it a name.'); return; }
        if (!body.expense_config_id) { msg('supPrMsg', 'Choose which expense category its use is charged to.'); return; }

        var btn = document.getElementById('supPrSave');
        btn.disabled = true;
        post('/supplies/products' + (editingProductId ? '/' + editingProductId : ''), body).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supPrMsg', (r.data && r.data.message) || 'Could not save.');
                return;
            }
            if (r.data.warning) { window.alert(r.data.warning); }
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supPrMsg', 'Could not reach the server.');
        });
    };

    // ---------- fix price ----------
    var correcting = null;

    window.supOpenCorrect = function (batchId, oldTotal) {
        if (!CAN_MANAGE) { return; }
        correcting = {batchId: batchId, oldTotal: oldTotal};
        msg('supCoMsg', '');
        document.getElementById('supCoAmount').value = '';
        document.getElementById('supCoReason').value = '';
        document.getElementById('supCoWas').textContent = 'Currently recorded as Rs ' + money(oldTotal) + '.';
        document.getElementById('supCoPreview').innerHTML = '';
        show('supCoPreview', false);
        show('supCoApply', false);
        open('supCorrectModal');
    };

    window.supPreviewCorrect = function () {
        var amount = parseFloat(document.getElementById('supCoAmount').value);
        if (!(amount > 0)) { msg('supCoMsg', 'Enter what was actually paid.'); return; }
        msg('supCoMsg', '');

        post('/supplies/batches/' + correcting.batchId + '/preview-correction', {total_cost: amount})
            .then(function (r) {
                if (!r.ok || !r.data.success) {
                    msg('supCoMsg', (r.data && r.data.message) || 'Could not work that out.');
                    return;
                }
                var p = r.data.preview;
                var diff = Number(p.difference);
                var html = '<div class="sup-total"><span>' +
                    (diff < 0 ? 'Comes back to ' : 'Comes out of ') + esc(p.paid_from || 'the account') +
                    '</span><span>Rs ' + money(Math.abs(diff)) + '</span></div>';

                if (!p.expenses.length) {
                    html += '<div class="sup-hint" style="margin-top:8px;">' +
                        'Nothing has been taken out of this purchase yet, so no expense entries change.</div>';
                } else {
                    html += '<div class="sup-hint" style="margin-top:10px;"><strong>' + p.expenses.length +
                        ' expense entr' + (p.expenses.length === 1 ? 'y' : 'ies') +
                        '</strong> already posted will be corrected, on their original dates:</div>' +
                        '<div class="sup-staged" style="margin-top:6px;">' +
                        p.expenses.map(function (e) {
                            return '<div class="sup-staged-row"><span>' + esc(e.request_number || '') +
                                ' <span class="sup-pmeta">' + esc(e.month || '') + '</span></span>' +
                                '<span>Rs ' + money(e.was) + ' → <strong>Rs ' + money(e.now) + '</strong></span></div>';
                        }).join('') + '</div>';

                    if (p.months_affected.length) {
                        html += '<div class="sup-msg sup-msg-err" style="display:block;margin-top:10px;">' +
                            '⚠ This changes the packaging figure for ' + esc(p.months_affected.join(', ')) +
                            '. If you have already reported that month, it will move.</div>';
                    }
                }
                document.getElementById('supCoPreview').innerHTML = html;
                show('supCoPreview', true);
                show('supCoApply', true);
            });
    };

    window.supApplyCorrect = function () {
        var amount = parseFloat(document.getElementById('supCoAmount').value);
        if (!(amount > 0)) { return; }
        var btn = document.getElementById('supCoApply');
        btn.disabled = true;
        post('/supplies/batches/' + correcting.batchId + '/correct-price', {
            total_cost: amount,
            reason: document.getElementById('supCoReason').value || null
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supCoMsg', (r.data && r.data.message) || 'Could not correct it.');
                return;
            }
            window.alert(r.data.message);
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supCoMsg', 'Could not reach the server. Nothing was changed.');
        });
    };

    // ---------- stock count ----------
    var counting = null;

    window.supOpenCount = function (productId) {
        if (!CAN_MANAGE) { return; }
        msg('supCnMsg', '');
        document.getElementById('supCnCounted').value = '';
        document.getElementById('supCnNote').value = '';
        document.getElementById('supCnWriteOff').checked = true;

        get('/supplies/stock').then(function (r) {
            var row = ((r.data && r.data.products) || []).filter(function (x) {
                return Number(x.id) === Number(productId);
            })[0];
            counting = {productId: productId, expected: row ? row.qty_remaining : 0};
            document.getElementById('supCnTitle').textContent =
                'Count the shelf — ' + ((row && row.name) || '');
            document.getElementById('supCnExpected').textContent = row
                ? ('The system thinks there ' + (row.packets_in_stock === 1 ? 'is' : 'are') + ' ' +
                   (row.packets_in_stock != null
                        ? row.packets_in_stock + ' packet(s)'
                        : trimQty(row.qty_remaining) + ' ' + row.unit) + ' on the shelf.')
                : '';
            open('supCountModal');
        });
    };

    window.supSaveCount = function () {
        var counted = parseFloat(document.getElementById('supCnCounted').value);
        if (!(counted >= 0)) { msg('supCnMsg', 'Enter how many are actually there.'); return; }
        var btn = document.getElementById('supCnSave');
        btn.disabled = true;
        post('/supplies/' + counting.productId + '/count', {
            counted: counted,
            write_off: document.getElementById('supCnWriteOff').checked,
            note: document.getElementById('supCnNote').value || null
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supCnMsg', (r.data && r.data.message) || 'Could not record the count.');
                return;
            }
            window.alert(r.data.message);
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supCnMsg', 'Could not reach the server. Nothing was recorded.');
        });
    };

    // ---------- approval switch ----------
    var sw = document.getElementById('supApprovalSwitch');
    if (sw) {
        sw.addEventListener('change', function () {
            var want = sw.checked;
            sw.disabled = true;
            post('/admin/operations/supply-approval', { required: want }).then(function (r) {
                sw.disabled = false;
                if (!r.ok || !r.data.success) { sw.checked = !want; window.alert('Could not change that.'); return; }
                window.alert(r.data.message);
            }).catch(function () { sw.disabled = false; sw.checked = !want; });
        });
    }

    // preload so the first modal opens instantly
    loadCatalogue();
})();

