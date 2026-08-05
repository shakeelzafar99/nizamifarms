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
        {{-- The attached bill / receipt photo, same lazy load. --}}
        <div class="d-section" id="nfhubDImgWrap" style="display:none">
            <h4>Bill / receipt</h4>
            <a id="nfhubDImgLink" href="#" target="_blank" rel="noopener"><img id="nfhubDImg" alt="Attached bill or receipt"></a>
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
        <div class="d-section">
            <h4>History</h4>
            <div style="font-size:12px;color:var(--ink3)">Corrections are append-only — a change shows here as a new row; nothing is edited in place.</div>
        </div>
    </div>
    <div class="drawer-foot">
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

                if (t.bill_image) {
                    var img = document.getElementById('nfhubDImg');
                    img.src = t.bill_image;
                    // /public-storage is the proxy; fall back to the /storage symlink if it 404s.
                    img.onerror = function () { img.onerror = null; img.src = t.bill_image.replace('/public-storage/', '/storage/'); };
                    document.getElementById('nfhubDImgLink').href = t.bill_image;
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

    document.addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('.t-row[data-d]') : null;
        if (!row) return;
        var d;
        try { d = JSON.parse(row.getAttribute('data-d')); } catch (err) { return; }
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
    };
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') window.nfhubCloseDrawer(); });
})();
</script>
