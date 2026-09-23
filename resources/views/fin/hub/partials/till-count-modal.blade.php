{{-- 🏦 COUNT THE TILL (Sep-2026) — the web door.

     The mobile door is the modal after check-out in AttendanceScreen.js. Both post the same
     shape and land in TillCountService::record, which is the only place a seal is taken.

     ⭐⭐ THE INPUT IS NEVER PRE-FILLED with the system figure. The whole value of the exercise
     is a number that came out of a drawer rather than off a screen; an anchored one records
     agreement that was never actually checked. The books' figure is shown — you cannot ask
     someone to reconcile against a secret — but it is shown BESIDE the box, never inside it.

     Rendered only when $isKeeper, so a reader who does not hold this cash never sees it. --}}
<div class="hubmodal" id="hubTillCount" onclick="if(event.target===this)tcClose()">
    <div class="hubmodal-box">
        <div class="hubmodal-head">
            <div>
                <h3>🏦 Count {{ $account->account_name }}</h3>
                <div class="hm-sub">A record only — counting never moves money</div>
            </div>
            <button class="hubmodal-x" type="button" onclick="tcClose()" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="tcErr"></div>

            <div class="stat-chip" style="display:block;margin-bottom:12px;">
                The books say
                <b class="num" style="font-size:20px;">Rs. {{ number_format($balance, 2) }}</b>
            </div>

            <div class="fld">
                <label for="tcAmount">How much are you actually holding?</label>
                <input type="number" step="0.01" min="0" id="tcAmount" inputmode="decimal"
                       placeholder="Count it and type the total"
                       oninput="tcPreview()" autocomplete="off"
                       style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:18px;font-weight:700;">
                <div id="tcHint" class="meta" style="margin-top:6px;min-height:18px;"></div>
            </div>

            <div class="fld" style="margin-top:10px;">
                <label for="tcNote">Note (optional)</label>
                <input type="text" id="tcNote" maxlength="200" placeholder="e.g. Rs 2,000 lent to Haider, not yet filed"
                       style="width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:9px;font-size:13px;">
            </div>
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="tcClose()">Cancel</button>
            <button class="btn primary" type="button" id="tcSave" onclick="tcSubmit()">Record my count</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var URL_COUNT = @js(route('fin.hub.account-count', $account->id));
    var SYSTEM    = {{ (float) $balance }};
    var busy      = false;

    function el(id) { return document.getElementById(id); }
    function money(n) {
        return 'Rs. ' + Number(n).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    window.tcOpen = function () {
        el('tcErr').textContent = '';
        el('tcErr').style.display = 'none';
        el('tcAmount').value = '';   // ⚠ never pre-filled — see the header note
        el('tcNote').value = '';
        el('tcHint').textContent = '';
        el('hubTillCount').classList.add('on');
        setTimeout(function () { el('tcAmount').focus(); }, 60);
    };

    window.tcClose = function () {
        if (busy) { return; }
        el('hubTillCount').classList.remove('on');
    };

    /** Live feedback as he types, so a fat finger is caught before it is recorded. */
    window.tcPreview = function () {
        var raw = el('tcAmount').value;
        var hint = el('tcHint');
        if (raw === '' || isNaN(Number(raw))) { hint.textContent = ''; return; }
        var diff = Number(raw) - SYSTEM;
        if (Math.abs(diff) < 0.005) {
            hint.innerHTML = '<b style="color:var(--in)">✓ Matches the books exactly</b>';
        } else if (diff < 0) {
            hint.innerHTML = '<b style="color:var(--out)">Short ' + money(Math.abs(diff)) + '</b> against the books';
        } else {
            hint.innerHTML = '<b style="color:var(--owe)">' + money(diff) + ' more</b> than the books say';
        }
    };

    window.tcSubmit = function () {
        if (busy) { return; }
        var raw = el('tcAmount').value;
        if (raw === '' || isNaN(Number(raw)) || Number(raw) < 0) {
            el('tcErr').textContent = 'Type the amount you counted.';
            el('tcErr').style.display = 'block';
            return;
        }

        busy = true;
        el('tcSave').disabled = true;
        el('tcSave').textContent = 'Recording…';

        fetch(URL_COUNT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify({counted_amount: Number(raw), note: el('tcNote').value || null})
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { throw new Error((d && d.message) || 'Could not record the count.'); }
            // Reload so the new checkpoint line appears in the ledger where it belongs,
            // rather than being patched into the DOM in a second, disagreeing way.
            if (typeof hubToast === 'function') { hubToast(d.message); }
            setTimeout(function () { window.location.reload(); }, 700);
        })
        .catch(function (e) {
            busy = false;
            el('tcSave').disabled = false;
            el('tcSave').textContent = 'Record my count';
            el('tcErr').textContent = e.message || 'Could not record the count.';
            el('tcErr').style.display = 'block';
        });
    };
})();
</script>
