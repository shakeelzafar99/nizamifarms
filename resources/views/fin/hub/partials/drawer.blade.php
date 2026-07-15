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
        </div>
        <div class="d-flow">
            <div class="d-acct src">From<b id="nfhubDFrom">—</b><span id="nfhubDFromSub"></span></div>
            <div class="d-arrow">→</div>
            <div class="d-acct dst">To<b id="nfhubDTo">—</b><span id="nfhubDToSub"></span></div>
        </div>
        <div class="d-section">
            <h4>Details</h4>
            <dl class="d-kv">
                <dt>Status</dt><dd id="nfhubDStatus">—</dd>
                <dt>Date</dt><dd id="nfhubDDate">—</dd>
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
        set('nfhubDFrom', d.from || '—');
        set('nfhubDFromSub', d.fromsub || '');
        set('nfhubDTo', d.to || '—');
        set('nfhubDToSub', d.tosub || '');
        set('nfhubDStatus', d.statusLabel || '—');
        set('nfhubDDate', d.date || '—');
        set('nfhubDBy', d.by || '—');
        var action = document.getElementById('nfhubDAction');
        action.href = d.url || '#';
        action.textContent = d.pending ? 'Review & approve ↗' : 'Open full details ↗';
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
