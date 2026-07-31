{{-- In-Hub product manager for a by-weight vendor.

     Same operations, same endpoints as the old /finance/vendors/{id}/products page
     (VendorProductController — store/update/toggle/set-default/destroy) — only the UI is Hub-styled.
     Rows are server-rendered from $vendorProducts (ALL products, inactive included: managing means
     seeing what's switched off; the ⚖ purchase modal keeps its active-only JSON list). Writes reload
     the page, the same pattern as every other Hub modal.

     Read-only users can open and look; every write control is hidden. --}}
@php $prReadOnly = auth()->user()?->isReadOnly(); @endphp
<div class="hubmodal" id="hubProducts" onclick="if(event.target===this)hubClose('hubProducts')">
    <div class="hubmodal-box wide">
        <div class="hubmodal-head">
            <div>
                <h3>Products</h3>
                <div class="hm-sub">{{ $vendor->vendor_name }} · line items for ⚖ purchases · <a href="/finance/vendors/{{ $vendor->id }}/products" style="color:inherit">full page ↗</a></div>
            </div>
            <button class="hubmodal-x" type="button" onclick="hubClose('hubProducts')" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="hubPrErr"></div>

            @unless($prReadOnly)
            {{-- Add / edit form. One form, two modes — hubPrEditId decides which endpoint. --}}
            <div class="pr-form">
                <input type="hidden" id="hubPrEditId" value="">
                <div class="fld-row" style="align-items:flex-end">
                    <div class="fld" style="flex:2"><label>Product</label><input type="text" id="hubPrName" placeholder="e.g. Mutton (boneless)"></div>
                    <div class="fld"><label>Unit</label><input type="text" id="hubPrUnit" placeholder="kg" value="kg"></div>
                    <div class="fld"><label>Rate / unit (Rs.)</label><input type="number" step="0.01" min="0.01" id="hubPrRate" placeholder="0.00"></div>
                    <div class="fld" style="flex:0 0 auto"><label style="text-transform:none;letter-spacing:0"><input type="checkbox" id="hubPrDefault" style="width:auto"> Default</label></div>
                    <div class="fld" style="flex:0 0 auto">
                        <button class="btn primary" type="button" id="hubPrSubmit" onclick="hubPrSubmit()">＋ Add product</button>
                        <button class="btn" type="button" id="hubPrCancelEdit" onclick="hubPrResetForm()" style="display:none">Cancel</button>
                    </div>
                </div>
                <span class="hint">The default product comes pre-selected on every ⚖ purchase line. Rates here are the prefills — each purchase line can still override its rate.</span>
            </div>
            @endunless

            @forelse($vendorProducts as $p)
                <div class="pr-row {{ $p->is_active ? '' : 'off' }}">
                    <span class="pr-name">
                        @if($p->is_default)<span class="pr-star" title="Default — pre-selected on purchase lines">★</span>@endif
                        {{ $p->product_name }}
                        @unless($p->is_active)<span class="inactive-tag">inactive</span>@endunless
                    </span>
                    <span class="pr-rate num">Rs. {{ number_format((float) $p->rate_per_unit, 2) }} <span class="pr-unit">/ {{ $p->unit }}</span></span>
                    @unless($prReadOnly)
                    <span class="row-actions">
                        <button class="mini-btn" type="button"
                            data-p='{{ json_encode(['id' => $p->id, 'name' => $p->product_name, 'unit' => $p->unit, 'rate' => (float) $p->rate_per_unit, 'def' => (bool) $p->is_default], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'
                            onclick="hubPrEdit(JSON.parse(this.dataset.p))">Edit</button>
                        @if(!$p->is_default && $p->is_active)
                            <button class="mini-btn" type="button" onclick="hubPrAction('{{ $p->id }}/set-default', 'Default set')" title="Pre-select this product on purchase lines">★ Default</button>
                        @endif
                        <button class="mini-btn" type="button" onclick="hubPrAction('{{ $p->id }}/toggle', '{{ $p->is_active ? 'Switched off' : 'Switched on' }}')">{{ $p->is_active ? 'Turn off' : 'Turn on' }}</button>
                        <button class="mini-btn" type="button" style="color:var(--out)" onclick="hubPrDelete({{ $p->id }}, @json($p->product_name))">Delete</button>
                    </span>
                    @endunless
                </div>
            @empty
                <div class="empty">No products yet{{ $prReadOnly ? '' : ' — add the first one above' }}.</div>
            @endforelse
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="hubClose('hubProducts')">Close</button>
        </div>
    </div>
</div>

<script>
(function(){
    var VID = @json($vendor->id);
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var el = function(id){ return document.getElementById(id); };
    function err(m){ var e=el('hubPrErr'); e.textContent=m; e.classList.add('on'); }
    function errHide(){ el('hubPrErr').classList.remove('on'); }
    function done(msg){ if(window.hubToast) hubToast(msg); setTimeout(function(){ location.reload(); }, 700); }
    function fd(extra){
        var f = new FormData(); f.append('_token', csrf);
        Object.keys(extra||{}).forEach(function(k){ f.append(k, extra[k]); });
        return f;
    }
    async function post(url, body){
        var r = await fetch(url, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: body});
        if(r.status === 422){ var j = await r.json(); throw new Error(Object.values(j.errors||{}).flat()[0] || 'Check the fields.'); }
        var j2 = await r.json().catch(function(){ return {success:r.ok}; });
        if(!j2.success) throw new Error(j2.message || 'Could not save.');
        return j2;
    }

    window.hubOpenProducts = function(){ errHide(); open('hubProducts'); };
    function open(id){ el(id).classList.add('on'); }

    window.hubPrResetForm = function(){
        el('hubPrEditId').value=''; el('hubPrName').value=''; el('hubPrUnit').value='kg';
        el('hubPrRate').value=''; el('hubPrDefault').checked=false;
        el('hubPrSubmit').textContent='＋ Add product'; el('hubPrCancelEdit').style.display='none';
    };
    window.hubPrEdit = function(p){
        errHide();
        el('hubPrEditId').value=p.id; el('hubPrName').value=p.name; el('hubPrUnit').value=p.unit;
        el('hubPrRate').value=p.rate; el('hubPrDefault').checked=!!p.def;
        el('hubPrSubmit').textContent='Save changes'; el('hubPrCancelEdit').style.display='';
        el('hubPrName').focus();
    };
    window.hubPrSubmit = async function(){
        errHide();
        var name=el('hubPrName').value.trim(), unit=el('hubPrUnit').value.trim(), rate=parseFloat(el('hubPrRate').value);
        if(!name) return err('Give the product a name.');
        if(!unit) return err('Give it a unit (kg, piece, …).');
        if(!(rate>0)) return err('Enter a rate per unit.');
        var id=el('hubPrEditId').value;
        var body = fd({product_name:name, unit:unit, rate_per_unit:rate, is_default: el('hubPrDefault').checked ? 1 : 0});
        var btn=el('hubPrSubmit'); btn.disabled=true;
        try{
            if(id){ body.append('_method','PUT'); await post('/finance/vendors/'+VID+'/products/'+id, body); done('Product updated'); }
            else { await post('/finance/vendors/'+VID+'/products', body); done('Product added'); }
        }catch(e){ err(e.message); btn.disabled=false; }
    };
    window.hubPrAction = async function(path, msg){
        errHide();
        try{ await post('/finance/vendors/'+VID+'/products/'+path, fd()); done(msg); }
        catch(e){ err(e.message); }
    };
    window.hubPrDelete = async function(id, name){
        if(!confirm('Delete "'+name+'"? If it has purchase history it will be switched off instead.')) return;
        errHide();
        var body = fd({_method:'DELETE'});
        try{
            var j = await post('/finance/vendors/'+VID+'/products/'+id, body);
            done(j.deactivated ? 'Product switched off (has purchase history)' : 'Product deleted');
        }catch(e){ err(e.message); }
    };
})();
</script>
