{{-- Create / Edit vendor modal. Posts to the existing fin.vendors.store (POST) and
     fin.vendors.update (PUT via _method) — both redirect on success, 422 JSON on validation.
     Expects $businessUnits, $paymentSources, $userDefaultBuId. --}}
<div class="hubmodal" id="hubVendor" onclick="if(event.target===this)hubCloseVendor()">
    <div class="hubmodal-box">
        <div class="hubmodal-head">
            <div><h3 id="hubVenTitle">New vendor</h3><div class="hm-sub">A payable account is created automatically</div></div>
            <button class="hubmodal-x" type="button" onclick="hubCloseVendor()" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="hubVenErr"></div>
            <form id="hubVendorForm" onsubmit="return false">
                @csrf
                <input type="hidden" name="_method" id="hubVenMethod" value="POST">
                <div class="fld"><label>Vendor name</label><input type="text" name="vendor_name" id="hubVenName" placeholder="e.g. Al-Madina Poultry"><span class="hint">Letters, numbers, spaces, - _ . ( ) only</span></div>
                <div class="fld-row">
                    <div class="fld"><label>Contact person</label><input type="text" name="contact_person" id="hubVenPerson"></div>
                    <div class="fld"><label>Phone</label><input type="text" name="contact_phone" id="hubVenPhone"></div>
                </div>
                <div class="fld"><label>Email</label><input type="email" name="contact_email" id="hubVenEmail"></div>
                <div class="fld-row">
                    <div class="fld"><label>Purchase method</label>
                        <select name="default_purchase_method" id="hubVenMethodSel">
                            <option value="by_total">By total (enter an amount)</option>
                            <option value="by_weight">By weight (line items)</option>
                        </select>
                    </div>
                    <div class="fld"><label>Business unit</label>
                        <select name="business_unit_id" id="hubVenBu">
                            @foreach($businessUnits as $bu)
                                <option value="{{ $bu->id }}" {{ (int)$userDefaultBuId === (int)$bu->id ? 'selected' : '' }}>{{ $bu->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="fld"><label>Default payment source</label>
                    <select name="default_payment_source_id" id="hubVenPaySrc">
                        <option value="">NF Cash (default)</option>
                        @foreach($paymentSources as $ps)
                            <option value="{{ $ps->id }}">{{ $ps->account_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fld" id="hubVenOpeningWrap"><label>Opening balance (Rs., optional)</label><input type="number" step="0.01" min="0" name="opening_balance" id="hubVenOpening" placeholder="0.00"><span class="hint">What NF already owes this vendor, if any</span></div>
            </form>
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="hubCloseVendor()">Cancel</button>
            <button class="btn primary" type="button" id="hubVenSubmit" onclick="hubSubmitVendor()">Create vendor</button>
        </div>
    </div>
</div>

<script>
(function(){
    var editId = null;
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    window.hubCloseVendor = function(){ document.getElementById('hubVendor').classList.remove('on'); };

    window.hubDeleteVendor = async function(id, name){
        if(!confirm('Delete vendor "'+name+'"?\n\nOnly allowed when the balance is zero and there is no transaction history — otherwise the server keeps it (mark inactive instead).')) return;
        try{
            var r = await fetch('/finance/vendors/'+id, {method:'DELETE', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','X-CSRF-TOKEN':csrf}});
            var j = await r.json();
            if(j.success){ hubToast('Vendor deleted'); setTimeout(function(){ location.reload(); }, 700); }
            else alert(j.message || 'Could not delete this vendor.');
        }catch(e){ alert('Network error.'); }
    };

    function reset(){
        var f = document.getElementById('hubVendorForm'); f.reset();
        document.getElementById('hubVenErr').classList.remove('on');
    }
    window.hubOpenVendorCreate = function(){
        reset(); editId = null;
        document.getElementById('hubVenTitle').textContent = 'New vendor';
        document.getElementById('hubVenSubmit').textContent = 'Create vendor';
        document.getElementById('hubVenMethod').value = 'POST';
        document.getElementById('hubVenOpeningWrap').style.display = '';
        document.getElementById('hubVendor').classList.add('on');
    };
    window.hubOpenVendorEdit = function(v){
        reset(); editId = v.id;
        document.getElementById('hubVenTitle').textContent = 'Edit ' + v.name;
        document.getElementById('hubVenSubmit').textContent = 'Save changes';
        document.getElementById('hubVenMethod').value = 'PUT';
        document.getElementById('hubVenOpeningWrap').style.display = 'none'; // no opening balance on edit
        document.getElementById('hubVenName').value = v.name || '';
        document.getElementById('hubVenPerson').value = v.contact_person || '';
        document.getElementById('hubVenPhone').value = v.contact_phone || '';
        document.getElementById('hubVenEmail').value = v.contact_email || '';
        document.getElementById('hubVenMethodSel').value = v.method || 'by_total';
        if(v.bu) document.getElementById('hubVenBu').value = v.bu;
        document.getElementById('hubVenPaySrc').value = v.pay_source || '';
        document.getElementById('hubVendor').classList.add('on');
    };
    function err(m){ var e=document.getElementById('hubVenErr'); e.textContent=m; e.classList.add('on'); }

    window.hubSubmitVendor = async function(){
        document.getElementById('hubVenErr').classList.remove('on');
        var f = document.getElementById('hubVendorForm');
        if(!f.vendor_name.value.trim()){ return err('Enter a vendor name.'); }
        var url = editId ? ('/finance/vendors/' + editId) : @json(route('fin.vendors.store'));
        var btn = document.getElementById('hubVenSubmit'); var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Saving…';
        try{
            var r = await fetch(url, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body:new FormData(f)});
            if(r.status===422){ var j=await r.json(); err(Object.values(j.errors||{}).flat()[0] || 'Please check the form.'); return; }
            if(r.ok && r.url && r.url.indexOf('/finance/vendors')>-1 && r.url.indexOf('/hub')===-1){
                hubToast(editId ? 'Vendor updated' : 'Vendor created'); setTimeout(function(){ location.reload(); }, 700); return;
            }
            err('Could not save — check the name (allowed characters) or use the full vendor page.');
        }catch(e){ err('Network error. Try again.'); }
        finally{ btn.disabled=false; btn.textContent=orig; }
    };
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') hubCloseVendor(); });
})();
</script>
