{{-- Transaction drawer. Populated client-side from each row's data-d attribute.
     For the actual approve/reject action it links into the existing, battle-tested
     fin.ledger.show page (parallel-run: we reuse the proven endpoint, not reimplement it). --}}
<div class="scrim" id="nfhubScrim" onclick="nfhubCloseDrawer()"></div>
<aside class="drawer" id="nfhubDrawer" aria-label="Transaction detail">
    <div class="drawer-head">
        <div>
            <h3 id="nfhubDTitle">Transaction</h3>
            <div class="d-sub" id="nfhubDSub">—</div>
        </div>
        <button class="drawer-close" type="button" onclick="nfhubCloseDrawer()" aria-label="Close">✕</button>
    </div>
    <div class="drawer-body">
        <div class="d-amount">
            <div class="d-big num" id="nfhubDAmount">Rs. 0.00</div>
            <div class="d-mode" id="nfhubDMode">—</div>
            {{-- Which of OUR banks the money went through. Shown ONLY when the row carries a bank
                 tag, which only online rows do — cash-to-cash entries stay exactly as they were. --}}
            <div class="d-bank" id="nfhubDBank" style="display:none"><span>🏦</span><b></b></div>
        </div>
        <div class="d-flow">
            <div class="d-acct src">From<b id="nfhubDFrom">—</b><span id="nfhubDFromSub"></span></div>
            <div class="d-arrow">→</div>
            <div class="d-acct dst">To<b id="nfhubDTo">—</b><span id="nfhubDToSub"></span></div>
        </div>
        {{-- How this row moved the balance. Shown for bank-statement rows, where an attribution
             entry (⇄ transfer, ⚖ fix, ⟲ reset) has no ledger record standing behind it. --}}
        <div class="d-section" id="nfhubDBalWrap" style="display:none">
            <h4>Balance</h4>
            <div class="d-bal">
                <div><span>Before</span><b class="num" id="nfhubDBefore">—</b></div>
                <div class="d-bal-arrow">→</div>
                <div><span>After</span><b class="num" id="nfhubDAfter">—</b></div>
            </div>
        </div>
        {{-- What was actually bought. Loaded on demand for ledger rows that have line items
             (weighted vendor purchases) — the peek used to stop at the total. --}}
        <div class="d-section" id="nfhubDItemsWrap" style="display:none">
            <h4>Products</h4>
            <div class="d-items" id="nfhubDItems"></div>
        </div>
        {{-- The attached bill / receipt photos, same lazy load. Multi-image (Aug-2026):
             a thumbnail per image; single-image rows look the same as before. --}}
        <div class="d-section" id="nfhubDImgWrap" style="display:none">
            <h4 id="nfhubDImgTitle">Bill / receipt</h4>
            <div class="d-gallery" id="nfhubDGallery"></div>
        </div>
        <div class="d-section">
            <h4>Details</h4>
            <dl class="d-kv">
                <dt>Status</dt><dd id="nfhubDStatus">—</dd>
                <dt>Date</dt><dd id="nfhubDDate">—</dd>
                {{-- Vendor payments post on their own date — shown only when it differs. --}}
                <dt id="nfhubDPostedT" style="display:none">Posted</dt><dd id="nfhubDPosted" style="display:none">—</dd>
                {{-- When it was typed into the system — matters when the date was backdated. Hidden
                     for rows that don't carry it (e.g. overview rows built elsewhere). --}}
                <dt id="nfhubDEnteredT" style="display:none">Entered</dt><dd id="nfhubDEntered" style="display:none">—</dd>
                <dt id="nfhubDAdjT" style="display:none">Adjustment</dt><dd id="nfhubDAdj" style="display:none">—</dd>
                <dt>Created by</dt><dd id="nfhubDBy">—</dd>
            </dl>
        </div>
        {{-- Quick edit, in place. Only ever shown for rows flagged `editable` (vendor statement
             rows, non-read-only) AND only once the transaction has actually been fetched — every
             field below is prefilled from that fetch, never from the row's display strings, so
             saving an untouched form cannot overwrite anything with a rounded or reformatted
             value. Weighted purchases never reach this form; see nfhubStartEdit(). --}}
        <div class="d-section" id="nfhubDEditWrap" style="display:none">
            <h4>Quick edit</h4>
            <div class="d-edit-err" id="nfhubDEditErr"></div>
            <div class="d-edit">
                <label>Amount (Rs.)
                    <input type="number" step="0.01" min="0.01" id="nfhubDEAmount">
                </label>
                <label>Date
                    <input type="date" id="nfhubDEDate">
                </label>
                <label>Description
                    <textarea id="nfhubDEDesc" rows="2"></textarea>
                </label>
                {{-- ONLINE payments only: which of OUR banks it left from (re-tag; the
                     description's "· via XX" token follows the change server-side). --}}
                <div id="nfhubDEBankWrap" style="display:none">
                    <label>🏦 Paid from bank</label>
                    <div class="bankchips" id="nfhubDEBankChips"></div>
                </div>
                <label>Images <span class="d-edit-opt">✕ a thumbnail to remove it · pick files to add — applied on save</span></label>
                <div class="img-mgr" id="nfhubDEImgs" style="display:none"></div>
                <input type="file" id="nfhubDEBill" accept="image/*" multiple>
                <div class="d-edit-note" id="nfhubDENote"></div>
            </div>
        </div>
        <div class="d-section">
            <h4>History</h4>
            <div style="font-size:12px;color:var(--ink3)">Corrections are append-only — a change shows here as a new row; nothing is edited in place.</div>
        </div>
    </div>
    <div class="drawer-foot">
        <div class="foot-btns" id="nfhubDEditBar" style="display:none">
            <button class="btn" type="button" onclick="nfhubCancelEdit()">Cancel</button>
            <button class="btn primary" type="button" id="nfhubDESave" onclick="nfhubSaveEdit()">Save changes</button>
        </div>
        <button class="btn" type="button" id="nfhubDEditBtn" onclick="nfhubStartEdit()" style="display:none;justify-content:center">✏ Edit this entry</button>
        <a class="btn primary" id="nfhubDAction" href="#" style="justify-content:center">Open full details ↗</a>
        <div class="d-hint">Approve / reject opens the full review page.</div>
    </div>
</aside>

<script>
(function () {
    var drawer = document.getElementById('nfhubDrawer');
    var scrim = document.getElementById('nfhubScrim');
    function set(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }
    function show(id, on) { var el = document.getElementById(id); if (el) el.style.display = on ? '' : 'none'; }
    function pair(tId, dId, val) { show(tId, !!val); show(dId, !!val); if (val) set(dId, val); }
    var money = function (n) { return 'Rs. ' + Number(n).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}); };

    // Everything the row's data-d can't carry (line items, the bill photo, posted date, the
    // weighted adjustment) is fetched once, on open, from the SAME endpoint the vendor edit modal
    // uses. `seq` guards against a slow response landing in a drawer the user has already moved on
    // from. Any failure just leaves the base peek intact — it never blocks the drawer.
    var seq = 0;
    // The row currently in the drawer, plus the transaction the fetch below returned for it.
    // `txn` stays null until that lands, and the Edit button stays disabled until it does —
    // editing off the row's display strings ("Rs. 40,000.00", "Aug 08, 2026") would post a
    // reformatted amount and an unparseable date.
    var cur = {d: null, txn: null};

    function loadExtras(d) {
        show('nfhubDItemsWrap', false);
        show('nfhubDImgWrap', false);
        pair('nfhubDPostedT', 'nfhubDPosted', null);
        pair('nfhubDAdjT', 'nfhubDAdj', null);
        var mine = ++seq;
        if (!d.url || !d.id) return; // attribution rows (⚖ fix, ⟲ reset) have no ledger record

        fetch('/finance/ledger/transaction/' + d.id, {headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (mine !== seq || !j || !j.success || !j.transaction) return;
                var t = j.transaction;
                cur.txn = t;
                refreshEditBtn();

                var items = t.line_items || [];
                if (items.length) {
                    document.getElementById('nfhubDItems').innerHTML = items.map(function (li) {
                        var qty = Number(li.quantity), rate = Number(li.rate_per_unit);
                        return '<div class="d-item">'
                            + '<span class="di-name">' + String(li.product_name || '—').replace(/[<>&]/g, '') + '</span>'
                            + '<span class="di-calc">' + qty + ' ' + (li.unit || '') + ' × ' + rate.toLocaleString() + '</span>'
                            + '<b class="di-total num">' + money(li.line_total) + '</b></div>';
                    }).join('');
                    show('nfhubDItemsWrap', true);
                }

                // Gallery: every attached image. Older payloads (backend not yet updated) have
                // no bill_images — degrade to the single bill_image, exactly the old behaviour.
                var gallery = (t.bill_images && t.bill_images.length)
                    ? t.bill_images
                    : (t.bill_image ? [{id: null, url: t.bill_image}] : []);
                if (gallery.length) {
                    var box = document.getElementById('nfhubDGallery');
                    box.innerHTML = gallery.map(function (im) {
                        return '<a href="' + im.url + '" target="_blank" rel="noopener">'
                            + '<img src="' + im.url + '" alt="Attached bill or receipt"></a>';
                    }).join('');
                    // /public-storage is the proxy; fall back to the /storage symlink if it 404s.
                    box.querySelectorAll('img').forEach(function (img) {
                        img.onerror = function () { img.onerror = null; img.src = img.src.replace('/public-storage/', '/storage/'); };
                    });
                    var ttl = document.getElementById('nfhubDImgTitle');
                    if (ttl) ttl.textContent = gallery.length > 1 ? ('Bill / receipt · ' + gallery.length + ' images') : 'Bill / receipt';
                    show('nfhubDImgWrap', true);
                }

                if (t.posted_date && t.posted_date !== '-' && t.posted_date !== t.transaction_date) {
                    pair('nfhubDPostedT', 'nfhubDPosted', t.posted_date);
                }
                if (Number(t.adjustment_amount)) {
                    pair('nfhubDAdjT', 'nfhubDAdj', money(t.adjustment_amount));
                }
                // Rows built without an "entered" stamp (the overview list) get it from here.
                var entD = document.getElementById('nfhubDEntered');
                if (entD && entD.style.display === 'none' && t.created_at && t.created_at !== '-') {
                    pair('nfhubDEnteredT', 'nfhubDEntered', t.created_at);
                }
            })
            .catch(function () { /* base peek stands */ });
    }

    // ---- Quick edit ---------------------------------------------------------------------
    // Deliberately thin: it collects values and hands them to hubSaveVendorTxn(), the SAME
    // function the vendor Edit modal posts through, so the two surfaces cannot validate or post
    // differently. That function lives in fin.hub.partials.vendor-op-modals, which is only on the
    // vendor detail page — hence the feature detect below rather than an assumption.
    function canEdit() {
        return !!(cur.d && cur.d.editable && typeof window.hubSaveVendorTxn === 'function');
    }
    function editing() {
        return document.getElementById('nfhubDEditWrap').style.display !== 'none';
    }
    function refreshEditBtn() {
        var btn = document.getElementById('nfhubDEditBtn');
        if (!btn) return;
        if (!canEdit() || editing()) { btn.style.display = 'none'; return; }
        btn.style.display = '';
        // Until the fetch lands we do not know the real amount/date, nor whether the row has line
        // items — both decide what a save would do, so the button waits rather than guessing.
        var ready = !!cur.txn;
        btn.disabled = !ready;
        btn.style.opacity = ready ? '' : '.55';
        btn.textContent = ready ? '✏ Edit this entry' : '✏ Loading…';
    }
    function editErr(msg) {
        var e = document.getElementById('nfhubDEditErr');
        e.textContent = msg || '';
        e.style.display = msg ? 'block' : 'none';
    }
    function closeEdit() {
        show('nfhubDEditWrap', false);
        show('nfhubDEditBar', false);
        editErr('');
        var f = document.getElementById('nfhubDEBill'); if (f) f.value = '';
        if (window.hubImgMgr) window.hubImgMgr.clear('nfhubDEImgs');
        if (window.hubBankChips) window.hubBankChips.clear('nfhubDEBankChips');
        var bw = document.getElementById('nfhubDEBankWrap'); if (bw) bw.style.display = 'none';
        footLink(true);
        refreshEditBtn();
    }
    // The footer's "open the old page" link and its hint belong to the read view — while the
    // quick-edit form is up they would sit under Save and read as a second, competing action.
    function footLink(on) {
        var act = document.getElementById('nfhubDAction');
        if (act) act.style.display = on ? '' : 'none';
        var foot = act ? act.closest('.drawer-foot') : null;
        var hint = foot ? foot.querySelector('.d-hint') : null;
        if (hint) hint.style.display = on ? '' : 'none';
    }

    window.nfhubStartEdit = function () {
        if (!canEdit() || !cur.txn) return;
        var t = cur.txn;

        // ⚠ A weighted purchase's amount is the SUM of its line items (+ adjustment). The update
        // endpoint only recomputes that when items[] is posted, so editing the amount here would
        // silently detach the total from the lines. Hand those to the line-item editor instead —
        // detected from the fetched items, not from the vendor's purchase method, because a
        // by-weight vendor can still record a plain purchase. If that editor is somehow absent,
        // do NOTHING: falling through to the simple form is never an acceptable fallback here.
        if ((t.line_items || []).length) {
            if (typeof window.hubOpenWeightedEdit === 'function') {
                window.nfhubCloseDrawer();
                window.hubOpenWeightedEdit(t.id);
            }
            return;
        }

        editErr('');
        // Prefilled from the fetch: the amount is the raw number, the date is already Y-m-d, and
        // the description is the stored text. Saving without touching anything is a no-op.
        document.getElementById('nfhubDEAmount').value = t.amount;
        document.getElementById('nfhubDEDate').value = (t.transaction_date && t.transaction_date !== '-') ? t.transaction_date : '';
        document.getElementById('nfhubDEDesc').value = t.description || '';
        document.getElementById('nfhubDEBill').value = '';
        // Current images, each removable — hubImgMgr ships with the vendor modals, which are
        // guaranteed present wherever canEdit() passed (same file defines hubSaveVendorTxn).
        if (window.hubImgMgr) window.hubImgMgr.render('nfhubDEImgs', t.bill_images || []);
        // Bank re-tag row — ONLINE vendor payments only (cash rows have no bank; purchases
        // never carry one; the backend ignores the field for both anyway).
        var bankWrap = document.getElementById('nfhubDEBankWrap');
        var offerBank = !!(window.hubBankChips && t.is_vendor_payment
            && (t.mode === 'online' || t.receiving_account_id));
        bankWrap.style.display = offerBank ? '' : 'none';
        if (offerBank) window.hubBankChips.render('nfhubDEBankChips', t.receiving_account_id);
        else if (window.hubBankChips) window.hubBankChips.clear('nfhubDEBankChips');

        // Say plainly what this form does NOT touch, so nobody edits here expecting more. These
        // are exactly the fields the vendor Edit modal leaves alone too.
        var notes = ['Posting date and payment source are unchanged — use “Open in old ledger” for those.'];
        if (Number(t.adjustment_amount)) {
            notes.push('This entry carries an adjustment of ' + money(t.adjustment_amount) + ', which stays as it is.');
        }
        document.getElementById('nfhubDENote').textContent = notes.join(' ');

        show('nfhubDEditWrap', true);
        show('nfhubDEditBar', true);
        document.getElementById('nfhubDEditBtn').style.display = 'none';
        footLink(false); // one primary action at a time
        document.getElementById('nfhubDEAmount').focus();
    };

    window.nfhubCancelEdit = function () { closeEdit(); };

    window.nfhubSaveEdit = async function () {
        if (!cur.txn) return;
        var btn = document.getElementById('nfhubDESave');
        editErr('');
        btn.disabled = true;
        var label = btn.textContent;
        btn.textContent = 'Saving…';
        var res = await window.hubSaveVendorTxn({
            id: cur.txn.id,
            transaction_date: document.getElementById('nfhubDEDate').value,
            amount: document.getElementById('nfhubDEAmount').value,
            description: document.getElementById('nfhubDEDesc').value,
            newFiles: document.getElementById('nfhubDEBill').files,
            removeImageIds: window.hubImgMgr ? window.hubImgMgr.removeIds('nfhubDEImgs') : [],
            receivingAccountId: window.hubBankChips ? window.hubBankChips.value('nfhubDEBankChips') : null,
        });
        if (res.ok) {
            if (window.hubToast) window.hubToast('Transaction updated');
            // Reload for the same reason every other Hub write does: the running balance, the day
            // and month totals and the header KPIs are all server-computed.
            setTimeout(function () { location.reload(); }, 800);
            return;
        }
        btn.disabled = false;
        btn.textContent = label;
        editErr(res.message || 'Could not update.');
    };

    document.addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('.t-row[data-d]') : null;
        if (!row) return;
        var d;
        try { d = JSON.parse(row.getAttribute('data-d')); } catch (err) { return; }
        cur = {d: d, txn: null};
        closeEdit();
        set('nfhubDTitle', d.title || 'Transaction');
        set('nfhubDSub', d.sub || '—');
        var amt = document.getElementById('nfhubDAmount');
        amt.textContent = d.amount || '—';
        amt.style.color = d.dir === 'in' ? 'var(--in)' : d.dir === 'out' ? 'var(--out)' : d.dir === 'owe' ? 'var(--owe)' : 'var(--ink)';
        set('nfhubDMode', d.mode || '—');
        var bankBox = document.getElementById('nfhubDBank');
        if (bankBox) {
            bankBox.style.display = d.bank ? '' : 'none';
            bankBox.querySelector('b').textContent = d.bank || '';
        }
        set('nfhubDFrom', d.from || '—');
        set('nfhubDFromSub', d.fromsub || '');
        set('nfhubDTo', d.to || '—');
        set('nfhubDToSub', d.tosub || '');
        set('nfhubDStatus', d.statusLabel || '—');
        set('nfhubDDate', d.date || '—');
        var entT = document.getElementById('nfhubDEnteredT'), entD = document.getElementById('nfhubDEntered');
        if (entT && entD) {
            entT.style.display = d.entered ? '' : 'none';
            entD.style.display = d.entered ? '' : 'none';
            entD.textContent = d.entered || '—';
        }
        set('nfhubDBy', d.by || '—');
        // Balance before → after (bank statements only).
        var balWrap = document.getElementById('nfhubDBalWrap');
        if (balWrap) {
            var hasBal = d.before != null || d.after != null;
            balWrap.style.display = hasBal ? '' : 'none';
            set('nfhubDBefore', d.before || '—');
            set('nfhubDAfter', d.after || '—');
        }

        // Attribution rows have no transaction page to open — hide the link rather than dead-end it.
        // The link goes to the OLD ledger page on purpose: approve/reject stays on the proven
        // screen. Labelled plainly so it isn't a surprise — the drawer above already carries the
        // detail, so this is only needed to act on the row or read its full audit trail.
        var action = document.getElementById('nfhubDAction');
        var foot = action.closest('.drawer-foot');
        var hint = foot ? foot.querySelector('.d-hint') : null;
        if (d.url) {
            if (foot) foot.style.display = '';
            action.href = d.url;
            action.textContent = d.pending ? 'Review & approve ↗' : 'Open in old ledger ↗';
            if (hint) {
                hint.textContent = d.pending
                    ? 'Approve / reject opens the full review page.'
                    : 'Opens the old ledger page — for the full audit trail.';
            }
        } else if (foot) {
            foot.style.display = 'none';
        }
        loadExtras(d);
        drawer.classList.add('on');
        scrim.classList.add('on');
    });

    window.nfhubCloseDrawer = function () {
        drawer.classList.remove('on');
        scrim.classList.remove('on');
        // Never leave a half-filled form behind for the next row that opens here.
        closeEdit();
    };
    // Escape backs out of the edit form first and only closes the drawer on a second press —
    // one reflex keystroke should not throw away what was typed.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (drawer.classList.contains('on') && editing()) { closeEdit(); return; }
        window.nfhubCloseDrawer();
    });
})();
</script>
