{{-- In-Hub Settle modal. Loads the rider's outstanding invoices from the existing
     fin.employee.outstanding-invoices JSON endpoint and posts to the existing
     fin.employee.short-cash-settlement endpoint (no backend change). Expects $expenseCategories. --}}
<div class="hubmodal" id="hubSettle" onclick="if(event.target===this)hubCloseSettle()">
    <div class="hubmodal-box wide">
        <div class="hubmodal-head">
            <div><h3>Settle rider</h3><div class="hm-sub" id="hubStName">—</div></div>
            <button class="hubmodal-x" type="button" onclick="hubCloseSettle()" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="hubStErr"></div>
            <div id="hubStLoading" style="padding:24px;text-align:center;color:var(--ink3)">Loading invoices…</div>
            <div id="hubStContent" style="display:none">
                <div class="inv-list" id="hubStList"></div>
                <div class="fld-row" style="margin-top:14px">
                    <div class="fld"><label>Cash being deposited (Rs.)</label><input type="number" step="0.01" min="0" id="hubStAmount" oninput="hubStRecompute()"></div>
                    <div class="fld"><label>Date</label><input type="date" id="hubStDate" value="{{ now()->format('Y-m-d') }}"></div>
                </div>
                <div class="fld" id="hubStShortWrap" style="display:none">
                    <label>Shortfall of <span id="hubStShortAmt" style="color:var(--out)">Rs. 0</span> — how is it covered?</label>
                    <select id="hubStCategory">
                        <option value="PENDING">Partial payment — keep the remainder open</option>
                        @foreach($expenseCategories as $cat)
                            <option value="{{ $cat }}">Expense: {{ $cat }} (deduct shortfall from rider)</option>
                        @endforeach
                    </select>
                    <span class="hint">“Partial” leaves the unpaid part on the rider. Choosing an expense category books the shortfall as that expense.</span>
                </div>
                <div class="hint" style="margin-top:6px">Deposits go to NF Cash and post as <b>pending</b> — a manager approves before balances update.</div>
            </div>
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="hubCloseSettle()">Cancel</button>
            <button class="btn primary" type="button" id="hubStSubmit" onclick="hubSubmitSettle()" disabled>Record deposit</button>
        </div>
    </div>
</div>

<script>
(function(){
    var accId = null, invoices = [];
    var fmt2 = function(n){ return 'Rs. ' + Number(n).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); };
    var fmt0 = function(n){ return 'Rs. ' + Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); };
    var csrf = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '';

    window.hubCloseSettle = function(){ document.getElementById('hubSettle').classList.remove('on'); };

    window.hubOpenSettle = async function(id, name){
        accId = id; invoices = [];
        document.getElementById('hubStName').textContent = name || '';
        document.getElementById('hubStErr').classList.remove('on');
        document.getElementById('hubStContent').style.display = 'none';
        document.getElementById('hubStLoading').style.display = '';
        document.getElementById('hubStSubmit').disabled = true;
        document.getElementById('hubSettle').classList.add('on');
        try{
            var r = await fetch('/finance/employee/'+id+'/outstanding-invoices', {headers:{'Accept':'application/json'}});
            var j = await r.json();
            invoices = (j && j.invoices) ? j.invoices : [];
            hubStRender();
        }catch(e){ hubStErr('Could not load invoices. Use the full page.'); }
    };

    function hubStRender(){
        document.getElementById('hubStLoading').style.display = 'none';
        document.getElementById('hubStContent').style.display = '';
        if(!invoices.length){
            document.getElementById('hubStList').innerHTML = '<div style="padding:16px;text-align:center;color:var(--ink3)">No open invoices — nothing to settle.</div>';
            document.getElementById('hubStAmount').value = '';
            document.getElementById('hubStSubmit').disabled = true;
            return;
        }
        document.getElementById('hubStList').innerHTML = invoices.map(function(inv,i){
            var out = Number(inv.outstanding_amount);
            var sub = inv.transaction_date + (inv.customer_name ? ' · ' + inv.customer_name : '');
            return '<label class="inv-row" style="cursor:pointer">'
                 + '<input type="checkbox" class="iv-chk hub-st-chk" data-i="'+i+'" data-out="'+out+'" checked onchange="hubStRecompute()">'
                 + '<span class="iv-main"><b>'+(inv.order_number && inv.order_number!=='N/A' ? 'Order #'+inv.order_number : 'Invoice')+'</b>'
                 + '<span class="iv-sub">'+sub+'</span></span>'
                 + '<span class="iv-amt num">'+fmt2(out)+'</span></label>';
        }).join('');
        document.getElementById('hubStSubmit').disabled = false;
        hubStSyncAmountToSelected();
    }

    function selectedTotal(){
        var t = 0;
        document.querySelectorAll('.hub-st-chk:checked').forEach(function(c){ t += Number(c.dataset.out); });
        return Math.round(t*100)/100;
    }
    function hubStSyncAmountToSelected(){ document.getElementById('hubStAmount').value = selectedTotal().toFixed(2); hubStRecompute(); }

    window.hubStRecompute = function(){
        var sel = selectedTotal();
        var dep = parseFloat(document.getElementById('hubStAmount').value) || 0;
        var short = Math.round((sel - dep)*100)/100;
        var wrap = document.getElementById('hubStShortWrap');
        if(short > 0.005){
            wrap.style.display = '';
            document.getElementById('hubStShortAmt').textContent = fmt0(short);
        } else {
            wrap.style.display = 'none';
        }
        document.getElementById('hubStSubmit').disabled = (sel <= 0);
    };

    function hubStErr(msg){ var e=document.getElementById('hubStErr'); e.textContent=msg; e.classList.add('on'); }

    window.hubSubmitSettle = async function(){
        document.getElementById('hubStErr').classList.remove('on');
        var ids = [].map.call(document.querySelectorAll('.hub-st-chk:checked'), function(c){ return invoices[c.dataset.i].id; });
        if(!ids.length){ return hubStErr('Select at least one invoice.'); }
        var sel = selectedTotal();
        var dep = parseFloat(document.getElementById('hubStAmount').value) || 0;
        if(!(dep > 0)){ return hubStErr('Enter the cash amount.'); }
        if(dep - sel > 0.01){ return hubStErr('Deposit cannot exceed the selected total (' + fmt2(sel) + ').'); }
        var short = Math.round((sel - dep)*100)/100;

        var fd = new FormData();
        fd.append('_token', csrf);
        fd.append('transaction_date', document.getElementById('hubStDate').value);
        fd.append('amount', dep.toFixed(2));
        fd.append('description', '');
        fd.append('destination_account_id', '');
        ids.forEach(function(id){ fd.append('invoice_ids[]', id); });
        if(short > 0.005){ fd.append('expense_category', document.getElementById('hubStCategory').value); }

        var btn = document.getElementById('hubStSubmit'); btn.disabled = true; btn.textContent = 'Recording…';
        try{
            var r = await fetch('/finance/employee/'+accId+'/short-cash-settlement', {
                method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: fd
            });
            if(r.status===422){ var j=await r.json(); hubStErr(Object.values(j.errors||{}).flat()[0] || 'Please check the form.'); return; }
            if(r.ok && r.url && r.url.indexOf('/finance/employee/')>-1 && r.url.indexOf('/hub')===-1){
                hubToast('Deposit recorded — pending approval'); setTimeout(function(){ location.reload(); }, 800); return;
            }
            hubStErr('Could not complete — check the amount and category, or use the full page.');
        }catch(e){ hubStErr('Network error. Try again.'); }
        finally{ btn.disabled = false; btn.textContent = 'Record deposit'; }
    };
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') hubCloseSettle(); });
})();
</script>
