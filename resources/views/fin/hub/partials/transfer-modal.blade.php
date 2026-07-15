{{-- In-Hub Account Transfer modal + shared toast. Posts to the existing fin.ledger.transfer.store
     endpoint (no backend change). Included globally via the nav partial so the "New Transfer"
     button works on every Hub page. --}}
<div class="hubmodal" id="hubTransfer" onclick="if(event.target===this)hubCloseTransfer()">
    <div class="hubmodal-box">
        <div class="hubmodal-head">
            <div><h3>New transfer</h3><div class="hm-sub">Move money between accounts</div></div>
            <button class="hubmodal-x" type="button" onclick="hubCloseTransfer()" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="hubTxErr"></div>
            <form id="hubTransferForm" onsubmit="return false">
                @csrf
                <div class="fld-row">
                    <div class="fld">
                        <label>From account</label>
                        <select name="from_account_id" id="hubTxFrom" onchange="hubTxOnAcctChange()"><option value="">Loading…</option></select>
                        <span class="hint" id="hubTxFromBal"></span>
                    </div>
                    <div class="fld">
                        <label>To account</label>
                        <select name="to_account_id" id="hubTxTo" onchange="hubTxOnAcctChange()"><option value="">Loading…</option></select>
                        <span class="hint" id="hubTxToBal"></span>
                    </div>
                </div>
                <div class="fld-row">
                    <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" name="amount" id="hubTxAmount" placeholder="0.00"></div>
                    <div class="fld"><label>Date</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}"></div>
                </div>
                <div class="fld" id="hubTxBankWrap" style="display:none">
                    <label>🏦 Which bank does this go through?</label>
                    <div class="bankchips" id="hubTxBankChips"></div>
                    <input type="hidden" name="receiving_account_id" id="hubTxBank">
                </div>
                <div class="fld">
                    <label>Mode</label>
                    <div style="display:flex;gap:16px;font-size:13px;padding-top:2px">
                        <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:500;color:var(--ink)"><input type="radio" name="mode" value="cash" checked style="width:auto"> Cash (immediate)</label>
                        <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:500;color:var(--ink)"><input type="radio" name="mode" value="online" style="width:auto"> Online (needs approval)</label>
                    </div>
                </div>
                <div class="fld"><label>Description</label><textarea name="description" rows="2" placeholder="e.g. NF Cash → rider for advance"></textarea></div>
            </form>
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="hubCloseTransfer()">Cancel</button>
            <button class="btn primary" type="button" id="hubTxSubmit" onclick="hubSubmitTransfer()">Process transfer</button>
        </div>
    </div>
</div>

<div class="hubtoast" id="hubToastEl">Done</div>

<script>
(function(){
    var data = null, banks = [];
    var fmt = function(n){ return 'Rs. ' + Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); };

    window.hubToast = function(msg){
        var t = document.getElementById('hubToastEl'); if(!t) return;
        t.textContent = msg; t.classList.add('on');
        clearTimeout(window._hubToastT); window._hubToastT = setTimeout(function(){ t.classList.remove('on'); }, 2400);
    };
    window.hubCloseTransfer = function(){ document.getElementById('hubTransfer').classList.remove('on'); };

    window.hubOpenTransfer = async function(){
        document.getElementById('hubTransfer').classList.add('on');
        document.getElementById('hubTxErr').classList.remove('on');
        if(data) return;
        try{
            var r = await fetch(@json(route('fin.hub.transfer-accounts')), {headers:{'Accept':'application/json'}});
            data = await r.json(); banks = data.banks || [];
            hubBuildSelects();
        }catch(e){ hubTxError('Could not load accounts. Use the full transfer page.'); }
    };

    function grpOptions(sel){
        var byType = {};
        (data.accounts||[]).forEach(function(a){ (byType[a.type]=byType[a.type]||[]).push(a); });
        var html = '<option value="">— Select —</option>';
        Object.keys(byType).forEach(function(t){
            html += '<optgroup label="'+t.charAt(0).toUpperCase()+t.slice(1)+'">';
            byType[t].forEach(function(a){
                html += '<option value="'+a.id+'" data-cat="'+a.category+'" data-bal="'+a.balance+'">'+a.name+' ('+fmt(a.balance)+')</option>';
            });
            html += '</optgroup>';
        });
        sel.innerHTML = html;
    }
    function hubBuildSelects(){ grpOptions(document.getElementById('hubTxFrom')); grpOptions(document.getElementById('hubTxTo')); }

    window.hubTxOnAcctChange = function(){
        var from = document.getElementById('hubTxFrom'), to = document.getElementById('hubTxTo');
        var fo = from.selectedOptions[0], too = to.selectedOptions[0];
        document.getElementById('hubTxFromBal').textContent = fo && fo.value ? 'Balance ' + fmt(fo.dataset.bal) : '';
        document.getElementById('hubTxToBal').textContent = too && too.value ? 'Balance ' + fmt(too.dataset.bal) : '';
        var touchesBank = (fo && fo.dataset.cat==='bank') || (too && too.dataset.cat==='bank');
        document.getElementById('hubTxBankWrap').style.display = touchesBank ? '' : 'none';
        if(touchesBank && !document.getElementById('hubTxBankChips').innerHTML){
            document.getElementById('hubTxBankChips').innerHTML = banks.map(function(b){
                return '<span class="bankchip" data-id="'+b.id+'" onclick="hubTxPickBank(this,'+b.id+')">'+(b.short_code||b.name)+' · '+fmt(b.balance)+'</span>';
            }).join('');
        }
        if(!touchesBank){ document.getElementById('hubTxBank').value=''; }
    };
    window.hubTxPickBank = function(el, id){
        document.querySelectorAll('#hubTxBankChips .bankchip').forEach(function(c){ c.classList.remove('on'); });
        el.classList.add('on'); document.getElementById('hubTxBank').value = id;
    };

    function hubTxError(msg){ var e=document.getElementById('hubTxErr'); e.textContent=msg; e.classList.add('on'); }

    window.hubSubmitTransfer = async function(){
        var f = document.getElementById('hubTransferForm');
        document.getElementById('hubTxErr').classList.remove('on');
        var from = f.from_account_id.value, to = f.to_account_id.value, amt = parseFloat(f.amount.value);
        if(!from || !to){ return hubTxError('Choose both accounts.'); }
        if(from===to){ return hubTxError('From and To must be different.'); }
        if(!(amt>0)){ return hubTxError('Enter an amount.'); }
        if(!f.description.value.trim()){ return hubTxError('Add a short description.'); }
        if(document.getElementById('hubTxBankWrap').style.display!=='none' && !document.getElementById('hubTxBank').value){
            return hubTxError('Pick which bank this transfer goes through.');
        }
        var btn = document.getElementById('hubTxSubmit'); btn.disabled = true; btn.textContent = 'Processing…';
        try{
            var r = await fetch(@json(route('fin.ledger.transfer.store')), {
                method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: new FormData(f)
            });
            if(r.status===422){ var j=await r.json(); hubTxError(Object.values(j.errors||{}).flat()[0] || 'Please check the form.'); return; }
            // Success redirects to the ledger index; a business error redirects back to the Hub.
            if(r.ok && r.url && r.url.indexOf('/finance/ledger')>-1 && r.url.indexOf('/hub')===-1){
                hubToast('Transfer recorded'); setTimeout(function(){ location.reload(); }, 700); return;
            }
            hubTxError('Could not complete — check the amount, balance and bank, or use the full transfer page.');
        }catch(e){ hubTxError('Network error. Try again.'); }
        finally{ btn.disabled = false; btn.textContent = 'Process transfer'; }
    };
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') hubCloseTransfer(); });
})();
</script>
