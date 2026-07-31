{{-- ⇄ Move money between our own banks (Taimur).

     The online total never changes here — only which bank holds it — so this writes a matched pair
     of attribution rows rather than a ledger transaction. The preview line spells that out: both new
     balances are shown, and the pool is stated as unchanged, so it can't be mistaken for spending.

     Included by the Banks tab and by a bank's statement; relies only on hubToast() (loaded globally
     via the nav partial) and the shared /data/transfer-accounts payload for the live bank balances. --}}
<div class="hubmodal" id="hubBankXfer" onclick="if(event.target===this)hubCloseBankXfer()">
    <div class="hubmodal-box">
        <div class="hubmodal-head">
            <div>
                <h3>Move money between banks</h3>
                <div class="hm-sub">Your online total stays the same — only which bank holds it changes</div>
            </div>
            <button class="hubmodal-x" type="button" onclick="hubCloseBankXfer()" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="hubBxErr"></div>
            <div class="fld-row">
                <div class="fld">
                    <label>From bank</label>
                    <select id="hubBxFrom" onchange="hubBxPreview()"><option value="">Loading…</option></select>
                </div>
                <div class="fld">
                    <label>To bank</label>
                    <select id="hubBxTo" onchange="hubBxPreview()"><option value="">Loading…</option></select>
                </div>
            </div>
            <div class="fld-row">
                <div class="fld">
                    <label>Amount (Rs.)</label>
                    <input type="number" step="0.01" min="0.01" id="hubBxAmount" placeholder="0.00" oninput="hubBxPreview()">
                </div>
                <div class="fld">
                    <label>Date</label>
                    <input type="date" id="hubBxDate" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}">
                </div>
            </div>
            <div class="bx-preview" id="hubBxPreviewBox" style="display:none"></div>
            <div class="fld">
                <label>Note (optional)</label>
                <input type="text" id="hubBxNote" placeholder="e.g. moved for vendor payments">
            </div>
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="hubCloseBankXfer()">Cancel</button>
            <button class="btn primary" type="button" id="hubBxSubmit" onclick="hubSubmitBankXfer()">Move money</button>
        </div>
    </div>
</div>

<script>
(function(){
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var banks = null;
    var fmt = function(n){ return 'Rs. ' + Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); };
    var el = function(id){ return document.getElementById(id); };
    function err(msg){ var e = el('hubBxErr'); e.textContent = msg; e.classList.add('on'); }

    window.hubCloseBankXfer = function(){ el('hubBankXfer').classList.remove('on'); };

    // preferFrom: opening from a bank's own statement pre-picks that bank as the source.
    window.hubOpenBankXfer = async function(preferFrom){
        el('hubBankXfer').classList.add('on');
        el('hubBxErr').classList.remove('on');
        if(!banks){
            try{
                var r = await fetch(@json(route('fin.hub.transfer-accounts')), {headers:{'Accept':'application/json'}});
                var j = await r.json();
                banks = j.banks || [];
            }catch(e){ banks = []; return err('Could not load the banks. Reload and try again.'); }
            var opts = '<option value="">— Select —</option>' + banks.map(function(b){
                return '<option value="'+b.id+'" data-bal="'+b.balance+'">'+b.name+' ('+fmt(b.balance)+')</option>';
            }).join('');
            el('hubBxFrom').innerHTML = opts;
            el('hubBxTo').innerHTML = opts;
        }
        if(preferFrom){ el('hubBxFrom').value = String(preferFrom); }
        hubBxPreview();
    };

    window.hubBxPreview = function(){
        var f = el('hubBxFrom').selectedOptions[0], t = el('hubBxTo').selectedOptions[0];
        var amt = parseFloat(el('hubBxAmount').value);
        var box = el('hubBxPreviewBox');
        if(!f || !f.value || !t || !t.value || !(amt > 0)){ box.style.display = 'none'; return; }
        if(f.value === t.value){ box.style.display = 'none'; return; }
        var fb = parseFloat(f.dataset.bal) - amt, tb = parseFloat(t.dataset.bal) + amt;
        box.style.display = '';
        box.innerHTML =
            '<div class="bx-line"><span>' + f.textContent.replace(/\s*\(.*\)$/, '') + '</span>'
              + '<b class="num">' + fmt(f.dataset.bal) + ' → <span class="' + (fb < 0 ? 'neg' : '') + '">' + fmt(fb) + '</span></b></div>'
          + '<div class="bx-line"><span>' + t.textContent.replace(/\s*\(.*\)$/, '') + '</span>'
              + '<b class="num">' + fmt(t.dataset.bal) + ' → ' + fmt(tb) + '</b></div>'
          + '<div class="bx-note">Online total unchanged'
              + (fb < 0 ? ' · this leaves the source bank negative' : '') + '</div>';
    };

    window.hubSubmitBankXfer = async function(){
        el('hubBxErr').classList.remove('on');
        var from = el('hubBxFrom').value, to = el('hubBxTo').value;
        var amt = parseFloat(el('hubBxAmount').value), date = el('hubBxDate').value;
        if(!from || !to){ return err('Choose both banks.'); }
        if(from === to){ return err('Pick two different banks.'); }
        if(!(amt > 0)){ return err('Enter an amount.'); }
        if(!date){ return err('Pick a date.'); }

        var btn = el('hubBxSubmit'); btn.disabled = true; btn.textContent = 'Moving…';
        try{
            var fd = new FormData();
            fd.append('_token', csrf);
            fd.append('from_bank_id', from);
            fd.append('to_bank_id', to);
            fd.append('amount', amt);
            fd.append('transfer_date', date);
            fd.append('note', el('hubBxNote').value || '');
            var r = await fetch(@json(route('fin.hub.bank-transfer')), {
                method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: fd
            });
            var j = await r.json().catch(function(){ return {}; });
            if(r.ok && j.success){
                if(window.hubToast) hubToast('Money moved');
                setTimeout(function(){ location.reload(); }, 700);
                return;
            }
            err(j.message || (j.errors ? Object.values(j.errors).flat()[0] : 'Could not move the money.'));
        }catch(e){ err('Network error. Try again.'); }
        finally{ btn.disabled = false; btn.textContent = 'Move money'; }
    };

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){ var m = el('hubBankXfer'); if(m) m.classList.remove('on'); }
    });
})();
</script>
