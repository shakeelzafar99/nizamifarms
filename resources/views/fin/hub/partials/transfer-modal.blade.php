{{-- In-Hub Account Transfer modal + shared toast.

     Two shapes, one form:
       • INTERNAL move — money changes hands between two of our own accounts. Posts to the existing
         fin.ledger.transfer.store endpoint (unchanged, still shared with the old transfer page).
       • OUTSIDE money — money entering or leaving the business (owner injection, outside refund, a
         figure that was simply wrong). Posts to fin.hub.external. Taimur only.

     Included once, globally, by the nav partial — do NOT @include it again on a page or you get a
     second copy of every element id. --}}
<div class="hubmodal" id="hubTransfer" onclick="if(event.target===this)hubCloseTransfer()">
    <div class="hubmodal-box">
        <div class="hubmodal-head">
            <div><h3>New transfer</h3><div class="hm-sub" id="hubTxSub">Move money between accounts</div></div>
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

                {{-- Only shown once "Outside" is picked, so the normal transfer stays as simple as it was. --}}
                <div class="note-card" id="hubTxExtNote" style="display:none;margin-bottom:12px">
                    <b id="hubTxExtTitle">Money from outside the business.</b>
                    <span id="hubTxExtBody">This changes the real total — it is not a move between two of our accounts.</span>
                </div>
                <div class="fld" id="hubTxExtWho" style="display:none">
                    <label id="hubTxExtWhoLabel">Who from (optional)</label>
                    <input type="text" id="hubTxSourceName" placeholder="e.g. Owner injection, refund from supplier">
                </div>

                <div class="fld-row">
                    <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" name="amount" id="hubTxAmount" placeholder="0.00"></div>
                    <div class="fld"><label>Date</label><input type="date" name="transaction_date" id="hubTxDate" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}"></div>
                </div>
                <div class="fld" id="hubTxBankWrap" style="display:none">
                    <label>🏦 Which bank does this go through?</label>
                    <div class="bankchips" id="hubTxBankChips"></div>
                    <input type="hidden" name="receiving_account_id" id="hubTxBank">
                    <span class="hint" id="hubTxBankHint"></span>
                </div>
                <div class="fld" id="hubTxModeWrap">
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
    var data = null, banks = [], canExternal = false;
    var EXT = '__outside__';
    var fmt = function(n){ return 'Rs. ' + Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); };
    var $ = function(id){ return document.getElementById(id); };

    window.hubToast = function(msg){
        var t = $('hubToastEl'); if(!t) return;
        t.textContent = msg; t.classList.add('on');
        clearTimeout(window._hubToastT); window._hubToastT = setTimeout(function(){ t.classList.remove('on'); }, 2400);
    };
    window.hubCloseTransfer = function(){ $('hubTransfer').classList.remove('on'); };

    window.hubOpenTransfer = async function(){
        $('hubTransfer').classList.add('on');
        $('hubTxErr').classList.remove('on');
        if(data) return;
        try{
            var r = await fetch(@json(route('fin.hub.transfer-accounts')), {headers:{'Accept':'application/json'}});
            data = await r.json(); banks = data.banks || []; canExternal = !!data.can_external;
            hubBuildSelects();
        }catch(e){ hubTxError('Could not load accounts. Use the full transfer page.'); }
    };

    function grpOptions(sel){
        // Grouped by what the money IS (company cash / online / staff cash), not by accounting type —
        // every account in this list is an asset, so the old type grouping was a single flat bucket.
        var byGroup = {}, order = [];
        (data.accounts||[]).forEach(function(a){
            if(!byGroup[a.group]){ byGroup[a.group] = []; order.push(a.group); }
            byGroup[a.group].push(a);
        });
        var html = '<option value="">— Select —</option>';
        if(canExternal){
            html += '<option value="'+EXT+'">🌍 Outside the business</option>';
        }
        order.forEach(function(g){
            html += '<optgroup label="'+g+'">';
            byGroup[g].forEach(function(a){
                html += '<option value="'+a.id+'" data-cat="'+a.category+'" data-bal="'+a.balance+'">'+a.name+' ('+fmt(a.balance)+')</option>';
            });
            html += '</optgroup>';
        });
        sel.innerHTML = html;
    }
    function hubBuildSelects(){ grpOptions($('hubTxFrom')); grpOptions($('hubTxTo')); }

    function isExt(sel){ return sel && sel.value === EXT; }

    window.hubTxOnAcctChange = function(){
        var from = $('hubTxFrom'), to = $('hubTxTo');
        var fo = from.selectedOptions[0], too = to.selectedOptions[0];
        var fromExt = isExt(from), toExt = isExt(to);
        var external = fromExt || toExt;

        $('hubTxFromBal').textContent = (fo && fo.value && !fromExt) ? 'Balance ' + fmt(fo.dataset.bal) : '';
        $('hubTxToBal').textContent   = (too && too.value && !toExt) ? 'Balance ' + fmt(too.dataset.bal) : '';

        // Outside money is recorded as a fact, immediately — the approval radio would only confuse.
        $('hubTxModeWrap').style.display = external ? 'none' : '';
        $('hubTxExtNote').style.display = external ? '' : 'none';
        $('hubTxExtWho').style.display = external ? '' : 'none';
        $('hubTxSub').textContent = external ? 'Record money entering or leaving the business' : 'Move money between accounts';
        $('hubTxSubmit').textContent = external ? (fromExt ? 'Add outside money' : 'Record money out') : 'Process transfer';
        if(external){
            $('hubTxExtTitle').textContent = fromExt ? 'Money from outside the business.' : 'Money leaving the business.';
            $('hubTxExtBody').textContent = fromExt
                ? 'This raises the real total — it is not a move between two of our accounts.'
                : 'This lowers the real total — it is not a move between two of our accounts.';
            $('hubTxExtWhoLabel').textContent = fromExt ? 'Who from (optional)' : 'Who to (optional)';
        }

        // The bank picker is about the COMPANY side only — "Outside" has no bank of ours.
        var touchesBank = (fo && !fromExt && fo.dataset.cat==='bank') || (too && !toExt && too.dataset.cat==='bank');
        $('hubTxBankWrap').style.display = touchesBank ? '' : 'none';
        if(touchesBank){
            var wanted = external ? 'ext' : 'int';
            if($('hubTxBankChips').dataset.mode !== wanted){
                var html = banks.map(function(b){
                    return '<span class="bankchip" data-id="'+b.id+'" onclick="hubTxPickBank(this,'+b.id+')">'+(b.short_code||b.name)+' · '+fmt(b.balance)+'</span>';
                }).join('');
                // Honest escape hatch: better a visible "No bank" row Taimur can assign later than a
                // guess that quietly mis-states a bank. Internal transfers keep requiring a bank.
                if(external){
                    html += '<span class="bankchip unsure" data-id="" onclick="hubTxPickBank(this,\'\')">⚠ Don\'t know yet</span>';
                }
                $('hubTxBankChips').innerHTML = html;
                $('hubTxBankChips').dataset.mode = wanted;
                $('hubTxBank').value = '';
                $('hubTxBankChips').dataset.picked = '';
            }
            $('hubTxBankHint').textContent = external
                ? 'Tagging it here keeps the bank and the online total in step.'
                : '';
        } else {
            $('hubTxBank').value = '';
            $('hubTxBankChips').dataset.picked = '';
        }
    };
    window.hubTxPickBank = function(el, id){
        document.querySelectorAll('#hubTxBankChips .bankchip').forEach(function(c){ c.classList.remove('on'); });
        el.classList.add('on');
        $('hubTxBank').value = id || '';
        // Distinguishes "picked Don't know yet" from "picked nothing at all".
        $('hubTxBankChips').dataset.picked = '1';
    };

    function hubTxError(msg){ var e=$('hubTxErr'); e.textContent=msg; e.classList.add('on'); }

    window.hubSubmitTransfer = async function(){
        var f = $('hubTransferForm');
        $('hubTxErr').classList.remove('on');
        var from = f.from_account_id.value, to = f.to_account_id.value, amt = parseFloat(f.amount.value);
        var fromExt = from === EXT, toExt = to === EXT;

        if(!from || !to){ return hubTxError('Choose both sides.'); }
        if(fromExt && toExt){ return hubTxError('Pick one of our accounts on one side.'); }
        if(from===to){ return hubTxError('From and To must be different.'); }
        if(!(amt>0)){ return hubTxError('Enter an amount.'); }
        if(!f.description.value.trim()){ return hubTxError('Add a short description.'); }

        var bankNeeded = $('hubTxBankWrap').style.display !== 'none';
        var external = fromExt || toExt;
        if(bankNeeded){
            if(external){
                if(!$('hubTxBankChips').dataset.picked){ return hubTxError('Pick the bank, or “Don\'t know yet”.'); }
            } else if(!$('hubTxBank').value){
                return hubTxError('Pick which bank this transfer goes through.');
            }
        }

        var btn = $('hubTxSubmit'); var label = btn.textContent;
        btn.disabled = true; btn.textContent = 'Processing…';
        try{
            if(external){
                var fd = new FormData();
                fd.append('_token', f._token.value);
                fd.append('account_id', fromExt ? to : from);
                fd.append('direction', fromExt ? 'in' : 'out');
                fd.append('amount', amt);
                fd.append('transaction_date', $('hubTxDate').value);
                fd.append('description', f.description.value.trim());
                fd.append('source_name', $('hubTxSourceName').value.trim());
                if(bankNeeded && $('hubTxBank').value){ fd.append('receiving_account_id', $('hubTxBank').value); }
                if(bankNeeded && !$('hubTxBank').value){ fd.append('allow_untagged', '1'); }
                var er = await fetch(@json(route('fin.hub.external')), {
                    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: fd
                });
                var ej = await er.json().catch(function(){ return {}; });
                if(er.ok && ej.success){
                    hubToast(ej.message || 'Recorded'); setTimeout(function(){ location.reload(); }, 700); return;
                }
                return hubTxError(ej.message || 'Could not record that.');
            }

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
        finally{ btn.disabled = false; btn.textContent = label; }
    };
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') hubCloseTransfer(); });
})();
</script>
