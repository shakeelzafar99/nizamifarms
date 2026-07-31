{{-- Vendor operational modals: purchase (by_total), weighted purchase (line items), payment,
     report, edit/delete transaction. Post to the existing fin.vendors.* endpoints.
     Expects $vendor, $payable, $paymentSources, $receivingBanks. --}}
@php
    $venBanks = $receivingBanks;
@endphp

{{-- ===== Purchase (by_total) ===== --}}
<div class="hubmodal" id="hubPurchase" onclick="if(event.target===this)hubClose('hubPurchase')">
  <div class="hubmodal-box">
    <div class="hubmodal-head"><div><h3>Record purchase</h3><div class="hm-sub">{{ $vendor->vendor_name }} · on account</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubPurchase')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubPuErr"></div>
      <form id="hubPurchaseForm" onsubmit="return false" enctype="multipart/form-data">@csrf
        <div class="fld-row">
          <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" name="amount" placeholder="0.00"></div>
          <div class="fld"><label>Date</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}"></div>
        </div>
        <div class="fld"><label>Description</label><textarea name="description" rows="2" placeholder="Entry by {{ auth()->user()->fullname ?? '' }}">Entry by {{ auth()->user()->fullname ?? '' }}</textarea></div>
        <div class="fld"><label>Bill image (optional)</label><input type="file" name="bill_image" accept="image/*"></div>
      </form>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubPurchase')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitPurchase()">Record purchase</button></div>
  </div>
</div>

{{-- ===== Weighted purchase (line items) ===== --}}
<div class="hubmodal" id="hubWeighted" onclick="if(event.target===this)hubClose('hubWeighted')">
  <div class="hubmodal-box wide">
    <div class="hubmodal-head"><div><h3>Purchase by weight</h3><div class="hm-sub">{{ $vendor->vendor_name }} · line items</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubWeighted')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubWtErr"></div>
      <div id="hubWtLines"></div>
      <button class="mini-btn" type="button" onclick="hubWtAddLine()" style="margin:6px 0 12px">＋ Add line</button>
      <div class="fld-row">
        <div class="fld"><label>Adjustment (Rs., +/−)</label><input type="number" step="0.01" id="hubWtAdj" value="0" oninput="hubWtTotal()"><span class="hint">discount (−) or extra (+)</span></div>
        <div class="fld"><label>Date</label><input type="date" id="hubWtDate" value="{{ now()->format('Y-m-d') }}"></div>
      </div>
      <div class="fld"><label>Description</label><input type="text" id="hubWtDesc" placeholder="Entry by {{ auth()->user()->fullname ?? '' }}" value="Entry by {{ auth()->user()->fullname ?? '' }}"></div>
      <div class="fld"><label>Bill image (optional)</label><input type="file" id="hubWtBill" accept="image/*"></div>
      <div class="inv-total"><span>Grand total</span><span class="num" id="hubWtGrand">Rs. 0.00</span></div>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubWeighted')">Cancel</button><button class="btn primary" type="button" id="hubWtSubmit" onclick="hubSubmitWeighted()" disabled>Record purchase</button></div>
  </div>
</div>

{{-- ===== Payment ===== --}}
<div class="hubmodal" id="hubPay" onclick="if(event.target===this)hubClose('hubPay')">
  <div class="hubmodal-box">
    <div class="hubmodal-head"><div><h3>Record payment</h3><div class="hm-sub">{{ $vendor->vendor_name }} · owed Rs. {{ number_format($payable, 0) }}</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubPay')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubPayErr"></div>
      <form id="hubPayForm" onsubmit="return false" enctype="multipart/form-data">@csrf
        <div class="fld-row">
          <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" max="{{ $payable }}" name="amount" placeholder="0.00"><span class="hint">max Rs. {{ number_format($payable, 2) }}</span></div>
          <div class="fld"><label>Pay from</label>
            <select name="payment_source_account_id" id="hubPaySrc" onchange="hubPaySrcChange()">
              <option value="" data-cat="cash">NF Cash (default)</option>
              @foreach($paymentSources as $ps)
                <option value="{{ $ps->id }}" data-cat="{{ $ps->account_category }}">{{ $ps->account_name }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="fld" id="hubPayBankWrap" style="display:none">
          <label>🏦 Which bank is this paid from?</label>
          <div class="bankchips" id="hubPayBankChips"></div>
          <input type="hidden" name="receiving_account_id" id="hubPayBank">
        </div>
        <div class="fld-row">
          <div class="fld"><label>Payment date</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}"></div>
          <div class="fld"><label>Posted date</label><input type="date" name="posted_date" value="{{ now()->format('Y-m-d') }}"></div>
        </div>
        <div class="fld"><label>Description (optional)</label><textarea name="description" rows="2"></textarea></div>
        <div class="fld"><label>Receipt image (optional)</label><input type="file" name="receipt_image" accept="image/*"></div>
      </form>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubPay')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitPayment()">Record payment</button></div>
  </div>
</div>

{{-- ===== Report ===== --}}
<div class="hubmodal" id="hubReport" onclick="if(event.target===this)hubClose('hubReport')">
  <div class="hubmodal-box wide">
    <div class="hubmodal-head"><div><h3>Vendor report</h3><div class="hm-sub">{{ $vendor->vendor_name }}</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubReport')">✕</button></div>
    <div class="hubmodal-body">
      <div class="filter-row" style="margin-bottom:12px">
        <div class="fld"><label>From</label><input type="date" id="hubRepFrom" value="{{ now()->startOfMonth()->format('Y-m-d') }}"></div>
        <div class="fld"><label>To</label><input type="date" id="hubRepTo" value="{{ now()->format('Y-m-d') }}"></div>
        <div class="fld" style="justify-content:flex-end"><label style="text-transform:none;letter-spacing:0"><input type="checkbox" id="hubRepPay" checked style="width:auto"> Show payments</label></div>
        <div class="fld"><label>&nbsp;</label><button class="btn primary" type="button" onclick="hubRunReport()">Generate</button></div>
      </div>
      <div class="m-err" id="hubRepErr"></div>
      <div id="hubRepOut"></div>
    </div>
    <div class="hubmodal-foot">
      <button class="btn" type="button" onclick="hubClose('hubReport')">Close</button>
      <button class="btn" type="button" id="hubRepCsv" onclick="hubReportCsv()" style="display:none">⤓ Excel (CSV)</button>
      <button class="btn primary" type="button" id="hubRepPrint" onclick="hubReportPrint()" style="display:none">🖨 Print</button>
    </div>
  </div>
</div>

{{-- ===== Edit transaction (simple) ===== --}}
<div class="hubmodal" id="hubEditTxn" onclick="if(event.target===this)hubClose('hubEditTxn')">
  <div class="hubmodal-box">
    <div class="hubmodal-head"><div><h3 id="hubEtTitle">Edit transaction</h3><div class="hm-sub">append-only — corrections keep history</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubEditTxn')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubEtErr"></div>
      <form id="hubEtForm" onsubmit="return false">@csrf
        <input type="hidden" id="hubEtId">
        <div class="fld-row">
          <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" id="hubEtAmount"></div>
          <div class="fld"><label>Date</label><input type="date" id="hubEtDate"></div>
        </div>
        <div class="fld"><label>Description</label><textarea id="hubEtDesc" rows="2"></textarea></div>
      </form>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubEditTxn')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitEditTxn()">Save changes</button></div>
  </div>
</div>

<script>
(function(){
    var VID = @json($vendor->id);
    var VNAME = @json($vendor->vendor_name);
    var PAYABLE = @json((float)$payable);
    var BANKS = @json($venBanks);
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var products = null;
    var fmt2 = function(n){ return Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); };

    window.hubClose = function(id){ document.getElementById(id).classList.remove('on'); };
    function open(id){ document.getElementById(id).classList.add('on'); }
    function errShow(id, m){ var e=document.getElementById(id); e.textContent=m; e.classList.add('on'); }
    function errHide(id){ document.getElementById(id).classList.remove('on'); }

    async function post(url, body, acceptJson){
        var headers = {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'};
        if(!(body instanceof FormData)){ headers['Content-Type']='application/json'; }
        return fetch(url, {method:'POST', headers: headers, body: body});
    }
    function done(msg){ hubToast(msg); setTimeout(function(){ location.reload(); }, 800); }

    // View a row's bill/receipt image. /storage first (symlink — fast), /public-storage as the
    // fallback for hosts where the symlink is missing (same pattern the attendance pages use).
    window.hubViewBill = function(p){
        var u='/storage/'+p, f='/public-storage/'+p, probe=new Image();
        probe.onload=function(){ window.open(u,'_blank'); };
        probe.onerror=function(){ window.open(f,'_blank'); };
        probe.src=u;
    };

    // ---------- Purchase (by_total) ----------
    // Optional date: the day-header ＋ buttons prefill the day they sit on.
    window.hubOpenPurchase = function(date){
        errHide('hubPuErr');
        var f=document.getElementById('hubPurchaseForm'); f.reset();
        if(date) f.transaction_date.value=date;
        open('hubPurchase');
    };
    window.hubSubmitPurchase = async function(){
        errHide('hubPuErr'); var f=document.getElementById('hubPurchaseForm');
        if(!(parseFloat(f.amount.value)>0)) return errShow('hubPuErr','Enter an amount.');
        try{
            var r = await post('/finance/vendors/'+VID+'/purchase', new FormData(f), true);
            if(r.status===422){ var j=await r.json(); return errShow('hubPuErr', Object.values(j.errors||{}).flat()[0]||'Check the form.'); }
            var j2 = await r.json().catch(function(){return{success:r.ok};});
            if(j2.success) return done('Purchase recorded');
            errShow('hubPuErr', j2.message || 'Could not record the purchase.');
        }catch(e){ errShow('hubPuErr','Network error.'); }
    };

    // ---------- Weighted purchase ----------
    window.hubOpenWeighted = async function(date){
        errHide('hubWtErr'); document.getElementById('hubWtLines').innerHTML=''; document.getElementById('hubWtAdj').value='0';
        document.getElementById('hubWtDate').value = date || @json(now()->format('Y-m-d'));
        open('hubWeighted');
        if(products===null){
            try{ var r=await fetch('/finance/vendors/'+VID+'/products/list',{headers:{'Accept':'application/json'}}); var j=await r.json(); products=(j.products||[]); }
            catch(e){ products=[]; }
        }
        if(!products.length){ errShow('hubWtErr','No products for this vendor yet. Add products first (Manage products).'); }
        hubWtAddLine();
    };
    function prodOptions(){
        return '<option value="">— product —</option>' + products.map(function(p){
            return '<option value="'+p.id+'" data-rate="'+p.rate_per_unit+'" data-unit="'+p.unit+'" data-name="'+String(p.product_name).replace(/"/g,'&quot;')+'" '+(p.is_default?'selected':'')+'>'+p.product_name+' ('+p.unit+' @ '+p.rate_per_unit+')</option>';
        }).join('');
    }
    window.hubWtAddLine = function(){
        var row = document.createElement('div'); row.className='wt-line'; row.style.cssText='display:flex;gap:6px;align-items:center;margin-bottom:6px';
        row.innerHTML =
            '<select class="wt-prod" style="flex:2;border:1px solid var(--line);border-radius:8px;padding:7px 8px;background:var(--surface);color:var(--ink);font-size:12.5px" onchange="hubWtProd(this)">'+prodOptions()+'</select>'+
            '<input class="wt-qty" type="number" step="0.001" min="0.001" placeholder="qty" style="flex:1;border:1px solid var(--line);border-radius:8px;padding:7px 8px;background:var(--surface);color:var(--ink);font-size:12.5px" oninput="hubWtTotal()">'+
            '<input class="wt-rate" type="number" step="0.01" placeholder="rate" style="flex:1;border:1px solid var(--line);border-radius:8px;padding:7px 8px;background:var(--surface);color:var(--ink);font-size:12.5px" oninput="hubWtTotal()">'+
            '<span class="wt-lt num" style="flex:1;text-align:right;font-weight:600;font-size:12.5px">0.00</span>'+
            '<button class="hubmodal-x" type="button" onclick="this.parentNode.remove();hubWtTotal()" style="font-size:15px">✕</button>';
        document.getElementById('hubWtLines').appendChild(row);
        var sel = row.querySelector('.wt-prod'); if(sel.value) hubWtProd(sel);
        hubWtTotal();
    };
    window.hubWtProd = function(sel){
        var o = sel.selectedOptions[0]; var row = sel.closest('.wt-line');
        if(o && o.value){ row.querySelector('.wt-rate').value = o.dataset.rate; }
        hubWtTotal();
    };
    window.hubWtTotal = function(){
        var sum=0, n=0;
        document.querySelectorAll('#hubWtLines .wt-line').forEach(function(r){
            var q=parseFloat(r.querySelector('.wt-qty').value)||0, rate=parseFloat(r.querySelector('.wt-rate').value)||0;
            var lt=q*rate; r.querySelector('.wt-lt').textContent=fmt2(lt); sum+=lt; if(r.querySelector('.wt-prod').value&&q>0)n++;
        });
        var adj=parseFloat(document.getElementById('hubWtAdj').value)||0;
        var grand=sum+adj;
        document.getElementById('hubWtGrand').textContent='Rs. '+fmt2(grand);
        document.getElementById('hubWtSubmit').disabled = !(grand>0 && n>0);
    };
    window.hubSubmitWeighted = async function(){
        errHide('hubWtErr');
        var fd=new FormData(); fd.append('_token',csrf);
        fd.append('transaction_date', document.getElementById('hubWtDate').value);
        fd.append('description', document.getElementById('hubWtDesc').value);
        fd.append('adjustment_amount', document.getElementById('hubWtAdj').value||'0');
        var bill=document.getElementById('hubWtBill').files[0]; if(bill) fd.append('bill_image', bill);
        var i=0, ok=false;
        document.querySelectorAll('#hubWtLines .wt-line').forEach(function(r){
            var o=r.querySelector('.wt-prod').selectedOptions[0]; var q=parseFloat(r.querySelector('.wt-qty').value)||0; var rate=parseFloat(r.querySelector('.wt-rate').value)||0;
            if(o&&o.value&&q>0&&rate>0){ ok=true;
                fd.append('items['+i+'][product_id]', o.value);
                fd.append('items['+i+'][quantity]', q);
                fd.append('items['+i+'][rate]', rate);
                fd.append('items['+i+'][unit]', o.dataset.unit||'');
                fd.append('items['+i+'][product_name]', o.dataset.name||'');
                i++;
            }
        });
        if(!ok) return errShow('hubWtErr','Add at least one product line with qty and rate.');
        try{
            var r=await post('/finance/vendors/'+VID+'/weighted-purchase', fd, true);
            if(r.status===422){ var j=await r.json(); return errShow('hubWtErr', Object.values(j.errors||{}).flat()[0]||'Check the lines.'); }
            var j2=await r.json().catch(function(){return{success:r.ok};});
            if(j2.success) return done('Weighted purchase recorded');
            errShow('hubWtErr', j2.message||'Could not record.');
        }catch(e){ errShow('hubWtErr','Network error.'); }
    };

    // ---------- Payment ----------
    // Optional prefill from a day header: both dates = that day (payments key off posted_date, and
    // "pay for this day" means the money moved that day), amount = the day's still-owed net —
    // capped at the vendor's payable so the max-guard can't instantly reject it.
    window.hubOpenPayment = function(date, amt){
        errHide('hubPayErr');
        var f=document.getElementById('hubPayForm'); f.reset();
        document.getElementById('hubPayBankWrap').style.display='none'; document.getElementById('hubPayBank').value='';
        if(date){ f.transaction_date.value=date; f.posted_date.value=date; }
        var v=Math.min(parseFloat(amt)||0, PAYABLE);
        if(v>0) f.amount.value=v.toFixed(2);
        open('hubPay');
    };
    window.hubPaySrcChange = function(){
        var o=document.getElementById('hubPaySrc').selectedOptions[0];
        var isBank = o && o.dataset.cat==='bank';
        document.getElementById('hubPayBankWrap').style.display = isBank ? '' : 'none';
        if(isBank && !document.getElementById('hubPayBankChips').innerHTML){
            document.getElementById('hubPayBankChips').innerHTML = BANKS.map(function(b){
                return '<span class="bankchip" onclick="hubPayPickBank(this,'+b.id+')">'+(b.short_code||b.name)+'</span>';
            }).join('');
        }
        if(!isBank) document.getElementById('hubPayBank').value='';
    };
    window.hubPayPickBank = function(el,id){ document.querySelectorAll('#hubPayBankChips .bankchip').forEach(function(c){c.classList.remove('on');}); el.classList.add('on'); document.getElementById('hubPayBank').value=id; };
    window.hubSubmitPayment = async function(){
        errHide('hubPayErr'); var f=document.getElementById('hubPayForm'); var amt=parseFloat(f.amount.value);
        if(!(amt>0)) return errShow('hubPayErr','Enter an amount.');
        if(amt-PAYABLE>0.01) return errShow('hubPayErr','Payment cannot exceed the balance (Rs. '+fmt2(PAYABLE)+').');
        if(document.getElementById('hubPayBankWrap').style.display!=='none' && !document.getElementById('hubPayBank').value) return errShow('hubPayErr','Pick which bank this is paid from.');
        try{
            var r=await post('/finance/vendors/'+VID+'/payment', new FormData(f), true);
            if(r.status===422){ var j=await r.json(); return errShow('hubPayErr', (j.message||Object.values(j.errors||{}).flat()[0])||'Check the form.'); }
            var j2=await r.json().catch(function(){return{success:r.ok};});
            if(j2.success) return done('Payment recorded');
            errShow('hubPayErr', j2.message||'Could not record the payment.');
        }catch(e){ errShow('hubPayErr','Network error.'); }
    };

    // ---------- Report ----------
    var reportData = null;
    window.hubOpenReport = function(){ errHide('hubRepErr'); document.getElementById('hubRepOut').innerHTML=''; document.getElementById('hubRepPrint').style.display='none'; document.getElementById('hubRepCsv').style.display='none'; open('hubReport'); };
    window.hubRunReport = async function(){
        errHide('hubRepErr');
        var from=document.getElementById('hubRepFrom').value, to=document.getElementById('hubRepTo').value;
        if(!from||!to) return errShow('hubRepErr','Pick both dates.');
        var sp=document.getElementById('hubRepPay').checked?'1':'0';
        try{
            var r=await fetch('/finance/vendors/report?vendor_id='+VID+'&date_from='+from+'&date_to='+to+'&show_payments='+sp, {headers:{'Accept':'application/json'}});
            var j=await r.json();
            if(!j.success) return errShow('hubRepErr', j.message||'Could not generate.');
            reportData=j.report; hubRenderReport(j.report);
            document.getElementById('hubRepPrint').style.display=''; document.getElementById('hubRepCsv').style.display='';
        }catch(e){ errShow('hubRepErr','Network error.'); }
    };
    function hubRenderReport(rep){
        var v=(rep.vendors||[])[0];
        var html='<div id="hubRepPrintable"><h3 style="margin:0 0 4px">'+VNAME+'</h3><div style="color:var(--ink3);font-size:12px;margin-bottom:10px">'+rep.date_from+' → '+rep.date_to+'</div>';
        if(!v){ html+='<div class="empty">No activity in this range.</div></div>'; document.getElementById('hubRepOut').innerHTML=html; return; }
        (v.daily_summary||[]).forEach(function(d){
            html+='<div class="day-head" style="margin-top:8px"><b>'+d.date+'</b><span>📦 '+fmt2(d.total_purchases)+' · 💵 '+fmt2(d.total_payments)+'</span></div>';
            html+='<table style="width:100%;border-collapse:collapse;margin-bottom:4px"><tbody>';
            (d.transactions||[]).forEach(function(t){
                html+='<tr><td style="padding:4px 8px;font-size:12px">'+(t.type==='purchase'?'📦 ':'💵 ')+(t.description||t.type)+(t.payment_mode?(' · '+t.payment_mode):'')+'</td><td style="padding:4px 8px;text-align:right;font-size:12px" class="num">'+fmt2(t.amount)+'</td></tr>';
            });
            html+='</tbody></table>';
        });
        html+='<div class="inv-total"><span>Purchases</span><span class="num">Rs. '+fmt2(v.total_purchases)+'</span></div>';
        html+='<div class="inv-total" style="border-top:none;margin-top:0"><span>Payments</span><span class="num">Rs. '+fmt2(v.total_payments)+'</span></div>';
        html+='<div class="inv-total" style="border-top:none;margin-top:0"><span>Balance now</span><span class="num">Rs. '+fmt2(v.current_balance)+'</span></div></div>';
        document.getElementById('hubRepOut').innerHTML=html;
    }
    window.hubReportPrint = function(){
        var w=window.open('','_blank'); var c=document.getElementById('hubRepPrintable');
        w.document.write('<html><head><title>'+VNAME+' report</title><style>body{font-family:Arial;padding:20px}table{width:100%;border-collapse:collapse}td{border-bottom:1px solid #eee}</style></head><body>'+(c?c.innerHTML:'')+'<div style="margin-top:20px;color:#888;font-size:11px">Nizami Farms</div></body></html>');
        w.document.close(); w.focus(); setTimeout(function(){ w.print(); }, 300);
    };
    window.hubReportCsv = function(){
        if(!reportData) return; var v=(reportData.vendors||[])[0]; if(!v) return;
        var rows=[['Date','Type','Description','Amount','Mode']];
        (v.daily_summary||[]).forEach(function(d){ (d.transactions||[]).forEach(function(t){ rows.push([d.date, t.type, (t.description||'').replace(/,/g,' '), t.amount, t.payment_mode||'']); }); });
        rows.push([]); rows.push(['Total purchases', v.total_purchases]); rows.push(['Total payments', v.total_payments]); rows.push(['Balance', v.current_balance]);
        var csv=rows.map(function(r){return r.join(',');}).join('\n');
        var a=document.createElement('a'); a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv); a.download=VNAME.replace(/\s+/g,'_')+'_report.csv'; a.click();
    };

    // ---------- Edit / delete transaction ----------
    window.hubOpenEditTxn = function(t){
        errHide('hubEtErr');
        document.getElementById('hubEtId').value=t.id;
        document.getElementById('hubEtAmount').value=t.amount;
        document.getElementById('hubEtDate').value=t.date;
        document.getElementById('hubEtDesc').value=t.desc||'';
        document.getElementById('hubEtTitle').textContent='Edit '+t.label;
        open('hubEditTxn');
    };
    window.hubSubmitEditTxn = async function(){
        errHide('hubEtErr');
        var fd=new FormData(); fd.append('_token',csrf);
        fd.append('transaction_date', document.getElementById('hubEtDate').value);
        fd.append('amount', document.getElementById('hubEtAmount').value);
        fd.append('description', document.getElementById('hubEtDesc').value);
        try{
            var r=await post('/finance/vendors/transaction/'+document.getElementById('hubEtId').value+'/update', fd, true);
            if(r.status===422){ var j=await r.json(); return errShow('hubEtErr', Object.values(j.errors||{}).flat()[0]||'Check the form.'); }
            var j2=await r.json(); if(j2.success) return done('Transaction updated');
            errShow('hubEtErr', j2.message||'Could not update.');
        }catch(e){ errShow('hubEtErr','Network error.'); }
    };
    window.hubDeleteTxn = async function(id){
        if(!confirm('Delete this transaction?\n\nIt reverses the balances and removes the row. This cannot be undone.')) return;
        try{
            var r=await post('/finance/vendors/transaction/'+id+'/delete', (function(){var f=new FormData();f.append('_token',csrf);return f;})(), true);
            var j=await r.json(); if(j.success) return done('Transaction deleted');
            alert(j.message||'Could not delete.');
        }catch(e){ alert('Network error.'); }
    };
    document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ ['hubPurchase','hubWeighted','hubPay','hubReport','hubEditTxn'].forEach(function(id){ document.getElementById(id).classList.remove('on'); }); } });
})();
</script>
