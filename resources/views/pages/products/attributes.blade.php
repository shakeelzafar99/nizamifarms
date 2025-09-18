@extends('layouts.app')

@section('title', 'Product Attributes')

@section('content')
<div class="container-fixed">
    <div class="flex items-center justify-between pb-7.5">
        <h1 class="text-xl font-semibold">Product Attributes</h1>
        <a href="{{ route('products.index') }}" class="kt-btn kt-btn-light">
            <i class="ki-filled ki-black-left"></i>
            Back to Products
        </a>
    </div>

    <div class="grid gap-5">
        <!-- Labels editor removed from here for a cleaner single-flow page -->

        <div class="card">
            <div class="card-header"><h3 class="card-title">Optional Auto Rules for New Products (with Priority)</h3></div>
            <div class="card-body">
                <div class="grid grid-cols-1 gap-4">
                    <div class="text-sm text-gray-600">When a product is created/imported and its title contains your text, the corresponding Category Level will be set automatically. No changes to existing products unless you run Apply Saved Rules.</div>
                    <div>
                        <label class="form-label">Select Level</label>
                        <select id="rulesAttribute" class="select" onchange="onChangeLevel(this.value)">
                            <option value="1" {{ ($activeKey ?? 1) == 1 ? 'selected' : '' }}>{{ $labels['1'] }}</option>
                            <option value="2" {{ ($activeKey ?? 1) == 2 ? 'selected' : '' }}>{{ $labels['2'] }}</option>
                            <option value="3" {{ ($activeKey ?? 1) == 3 ? 'selected' : '' }}>{{ $labels['3'] }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 items-end">
                        <div>
                            <label class="form-label">Match (in Title)</label>
                            <input type="text" class="input" id="newRuleMatch" placeholder="e.g., chicken">
                        </div>
                        <div>
                            <label class="form-label">Group to Set</label>
                            <input type="text" class="input" id="newRuleGroup" placeholder="e.g., Chicken">
                        </div>
                        <div>
                            <label class="form-label">Priority</label>
                            <input type="number" class="input" id="newRulePriority" value="0" min="0">
                        </div>
                        <button class="kt-btn kt-btn-light" type="button" onclick="addRuleFromForm()">Add Rule</button>
                    </div>
                    <div class="text-sm text-gray-600">Rules for this Level (drag rows to reorder; top = highest priority):</div>
                    <div class="overflow-auto">
                        <table class="table" id="rulesTable">
                            <thead>
                                <tr><th style="width:32px;">#</th><th>Match (in Title)</th><th>Group to Set</th><th style="width:90px;">Priority</th><th style="width:100px;">Actions</th></tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="flex gap-2">
                        <button class="kt-btn kt-btn-primary" type="button" onclick="saveRules()">Save Rules</button>
                        <button class="kt-btn" type="button" onclick="previewAutoRulesUI()">Preview Against Existing</button>
                        <button class="kt-btn kt-btn-success" type="button" onclick="applySavedRulesUI()">Apply Saved Rules</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manual assign section removed to unify the flow around saved rules -->

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Current Assignments (Top 20) — {{ $labels[(string)$activeKey] }}</h3>
                <div class="card-toolbar">
                    <button class="kt-btn kt-btn-light" type="button" onclick="refreshCoverageSummary()">
                        <i class="ki-filled ki-arrows-circle"></i>
                        Refresh Coverage
                    </button>
                </div>
            </div>
            <div class="card-body">
                @if(($assignStats ?? collect())->isEmpty())
                    <div class="text-sm text-gray-600">No assignments found for this level (non-empty values).</div>
                @else
                    <table class="table">
                        <thead><tr><th>Value</th><th>Count</th></tr></thead>
                        <tbody>
                        @foreach($assignStats as $row)
                            <tr><td>{{ $row->value }}</td><td>{{ $row->cnt }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
                
                <!-- Coverage Summary -->
                <div id="coverageSummary" class="mt-4 p-3 bg-gray-50 rounded">
                    <div class="text-sm font-medium text-gray-700 mb-2">📊 Coverage Summary for {{ $labels[(string)$activeKey] }}</div>
                    <div id="coverageContent" class="text-sm text-gray-600">
                        Click "Refresh Coverage" to see current categorization status
                    </div>
                </div>
            </div>
        </div>

        <!-- Removed legacy Apply Rules block to keep a single top-only flow -->
    </div>
</div>

<script>
// Build product IDs from picker + optional CSV fallback
function getSelectedProductIds(){
    const hidden = document.getElementById('pickerHidden').value;
    const fromPicker = hidden ? hidden.split(',').map(v=>parseInt(v,10)).filter(v=>!isNaN(v)) : [];
    const csv = (document.getElementById('product_ids_csv') ? document.getElementById('product_ids_csv').value : '') || '';
    const fromCsv = csv.split(',').map(v=>parseInt(v.trim(),10)).filter(v=>!isNaN(v));
    const set = new Set([...fromPicker, ...fromCsv]);
    return Array.from(set);
}

function prepareIds(e){
    e.preventDefault();
    const ids = getSelectedProductIds();
    const form = e.target.closest('form');
    form.querySelectorAll('input[name="product_ids[]"]').forEach(n => n.remove());
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'product_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    form.submit();
}

// Simple client-side editor for JSON rules
const rulesState = { 1: [], 2: [], 3: [] };
// Initialize with server data for the active key
rulesState[@json($activeKey)] = @json($activeRules);

function onChangeLevel(){ 
    // When level changes, we need to fetch rules for that level from server
    const level = document.getElementById('rulesAttribute').value;
    if (!rulesState[level] || rulesState[level].length === 0) {
        // Load rules for this level from server
        window.location.href = '{{ route('products.attributes') }}?level=' + level;
    } else {
        renderRulesTable(); 
    }
}
function addRuleFromForm(){
    const level = document.getElementById('rulesAttribute').value;
    const match = (document.getElementById('newRuleMatch').value || '').trim();
    const group = (document.getElementById('newRuleGroup').value || '').trim();
    const priority = parseInt(document.getElementById('newRulePriority').value || '0', 10) || 0;
    if (!match || !group) { alert('Please enter both match and group.'); return; }
    rulesState[level] = rulesState[level] || [];
    rulesState[level].push({ match, group, priority });
    document.getElementById('newRuleMatch').value='';
    document.getElementById('newRuleGroup').value='';
    renderRulesTable();
}
function saveRules(){
    const level = parseInt(document.getElementById('rulesAttribute').value,10);
    const rows = Array.from(document.querySelectorAll('#rulesTable tbody tr'));
    const rules = rows.map((tr, idx) => ({ match: tr.dataset.match, group: tr.dataset.group, priority: rows.length - idx }));
    fetch('{{ route('products.attributes.save_rules') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept':'application/json','Content-Type':'application/json' }, body: JSON.stringify({ attribute_key: level, rules }) })
    .then(r=>r.json()).then(data => { 
        rulesState[level] = rules; 
        renderRulesTable(); 
        showRuleSummary(data);
    })
    .catch(()=>alert('Error saving rules'));
}

function showRuleSummary(data) {
    if (!data.success || !data.summary) return;
    
    let message = 'Rules Saved Successfully!\n\n';
    message += '📊 Rule Coverage Summary:\n';
    message += '─'.repeat(40) + '\n';
    
    data.summary.forEach(rule => {
        message += `"${rule.match}" → ${rule.group}: ${rule.matching_products} products\n`;
    });
    
    message += '─'.repeat(40) + '\n';
    message += `✅ Categorized: ${data.categorized_products} products\n`;
    message += `❓ Uncategorized: ${data.uncategorized_products} products\n`;
    message += `📦 Total Products: ${data.total_products}\n`;
    
    if (data.uncategorized_products > 0) {
        const coverage = ((data.categorized_products / data.total_products) * 100).toFixed(1);
        message += `\n📈 Coverage: ${coverage}%`;
    }
    
    alert(message);
}
function removeRule(idx){
    const level = document.getElementById('rulesAttribute').value;
    rulesState[level].splice(idx,1); renderRulesTable();
}
function renderRulesTable(){
    const level = document.getElementById('rulesAttribute').value;
    const tbody = document.querySelector('#rulesTable tbody');
    tbody.innerHTML = '';
    (rulesState[level]||[]).forEach((r, i) => {
        const tr = document.createElement('tr');
        tr.draggable = true; tr.dataset.index = i; tr.dataset.match = r.match; tr.dataset.group = r.group;
        tr.innerHTML = `<td style="cursor:move">≡</td><td>${r.match}</td><td>${r.group}</td><td>${r.priority||0}</td><td><button type="button" class="kt-btn kt-btn-light" onclick="removeRule(${i})">Remove</button></td>`;
        tr.addEventListener('dragstart', onDragStart);
        tr.addEventListener('dragover', onDragOver);
        tr.addEventListener('drop', onDrop);
        tbody.appendChild(tr);
    });
}
let dragIndex = null;
function onDragStart(e){ dragIndex = parseInt(e.currentTarget.dataset.index,10); }
function onDragOver(e){ e.preventDefault(); }
function onDrop(e){
    e.preventDefault();
    const level = document.getElementById('rulesAttribute').value;
    const targetIndex = parseInt(e.currentTarget.dataset.index,10);
    if (dragIndex === null || targetIndex === dragIndex) return;
    const arr = rulesState[level] || [];
    const [moved] = arr.splice(dragIndex,1);
    arr.splice(targetIndex,0,moved);
    rulesState[level] = arr; dragIndex = null; renderRulesTable();
}

async function previewAssign(){
    const form = document.getElementById('assignForm');
    const formData = new FormData(form);
    const ids = getSelectedProductIds();
    formData.delete('product_ids[]');
    ids.forEach(id => formData.append('product_ids[]', id));
    const payload = Object.fromEntries(formData.entries());
    // Convert multiple product_ids[] back to array
    payload['product_ids'] = ids;
    const res = await fetch('{{ route('products.attributes.preview') }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success){
        const info = document.getElementById('previewInfo');
        const sample = (data.sample || []).map(s => `#${s.id} ${s.title}`).join(', ');
        info.textContent = `Will apply to ${data.count} products${sample ? ' (sample: ' + sample + ')' : ''}`;
    }
}

// Product selector logic
const pickerState = { ids: [], map: {} };
function renderChips(){
    const chips = document.getElementById('pickerChips');
    chips.innerHTML = '';
    pickerState.ids.forEach(id => {
        const chip = document.createElement('span');
        chip.className = 'px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs flex items-center gap-1';
        chip.innerHTML = `${id} ${(pickerState.map[id]||'').replaceAll('<','&lt;')} <button type="button" onclick="removePicked(${id})" class="ml-1">×</button>`;
        chips.appendChild(chip);
    });
    document.getElementById('pickerHidden').value = pickerState.ids.join(',');
}
function removePicked(id){
    pickerState.ids = pickerState.ids.filter(v => v !== id);
    renderChips();
}
async function searchProducts(q){
    const box = document.getElementById('pickerResults');
    if (!q || q.length < 2){ box.style.display='none'; box.innerHTML=''; return; }
    const res = await fetch(`{{ route('products.lookup') }}?q=${encodeURIComponent(q)}&limit=15`, { headers: { 'Accept': 'application/json' } });
    const data = await res.json();
    if (!data.success){ box.style.display='none'; return; }
    box.innerHTML = '';
    (data.products||[]).forEach(p => {
        const item = document.createElement('div');
        item.className = 'px-2 py-1 hover:bg-gray-50 cursor-pointer text-sm';
        item.textContent = `#${p.id} ${p.title}`;
        item.onclick = () => { if (!pickerState.ids.includes(p.id)){ pickerState.ids.push(p.id); pickerState.map[p.id]=p.title; renderChips(); } box.style.display='none'; };
        box.appendChild(item);
    });
    box.style.display = 'block';
}

// Auto-rules preview across existing products
async function previewAutoRules(attrKey){
    const res = await fetch('{{ route('products.attributes.preview_auto') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ attribute_key: attrKey }) });
    const data = await res.json();
    if (!data.success) return;
    const results = data.results || {};
    let msg = [];
    Object.keys(results).forEach(k => {
        const r = results[k];
        const sample = (r.sample||[]).map(s => `#${s.id} ${s.title}`).join(', ');
        msg.push(`Attribute ${k}: ${r.count} matches${sample? ' (sample: ' + sample + ')':''}`);
    });
    alert(msg.join('\n'));
}

function previewAutoRulesUI(){
    previewAutoRules(parseInt(document.getElementById('rulesAttribute').value,10));
}
async function applySavedRulesUI(){
    const attribute_key = parseInt(document.getElementById('rulesAttribute').value,10);
    const res = await fetch('{{ route('products.attributes.apply_saved') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ attribute_key }) });
    const data = await res.json();
    if (data.success) {
        alert('Updated ' + data.updated + ' products.');
        // Refresh coverage after applying rules
        refreshCoverageSummary();
    }
}

async function refreshCoverageSummary() {
    const level = parseInt(document.getElementById('rulesAttribute').value, 10);
    const contentDiv = document.getElementById('coverageContent');
    
    contentDiv.innerHTML = '<div class="text-sm text-gray-500">Loading...</div>';
    
    try {
        // Get current rules for this level
        const rules = rulesState[level] || [];
        
        // Simulate the save rules call to get coverage data
        const res = await fetch('{{ route('products.attributes.save_rules') }}', { 
            method: 'POST', 
            headers: { 
                'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                'Accept': 'application/json',
                'Content-Type': 'application/json' 
            }, 
            body: JSON.stringify({ attribute_key: level, rules }) 
        });
        
        const data = await res.json();
        
        if (data.success && data.summary) {
            let html = '<div class="grid grid-cols-1 gap-2">';
            
            // Rule matches
            if (data.summary.length > 0) {
                html += '<div class="text-xs font-medium text-gray-700">Rule Matches:</div>';
                data.summary.forEach(rule => {
                    html += `<div class="text-xs text-gray-600">• "${rule.match}" → ${rule.group}: <span class="font-medium">${rule.matching_products}</span> products</div>`;
                });
            } else {
                html += '<div class="text-xs text-gray-600">No rules defined yet</div>';
            }
            
            // Summary stats
            html += '<div class="border-t pt-2 mt-2">';
            html += `<div class="text-xs text-green-600">✅ Categorized: <span class="font-medium">${data.categorized_products}</span></div>`;
            html += `<div class="text-xs text-orange-600">❓ Uncategorized: <span class="font-medium">${data.uncategorized_products}</span></div>`;
            html += `<div class="text-xs text-gray-600">📦 Total: <span class="font-medium">${data.total_products}</span></div>`;
            
            if (data.total_products > 0) {
                const coverage = ((data.categorized_products / data.total_products) * 100).toFixed(1);
                html += `<div class="text-xs text-blue-600">📈 Coverage: <span class="font-medium">${coverage}%</span></div>`;
            }
            
            html += '</div></div>';
            contentDiv.innerHTML = html;
        } else {
            contentDiv.innerHTML = '<div class="text-xs text-red-600">Error loading coverage data</div>';
        }
    } catch (error) {
        contentDiv.innerHTML = '<div class="text-xs text-red-600">Error loading coverage data</div>';
    }
}

function loadRulesForLevel(level){
    // Reset entry rows and list; leave saved set in rulesState
    document.getElementById('rulesContainer').innerHTML = '';
    renderRulesList();
}
function renderRulesList(){
    const level = document.getElementById('rulesAttribute').value;
    const list = document.getElementById('rulesList');
    const rules = rulesState[level] || [];
    if (!rules.length){ list.textContent = 'No rules defined.'; return; }
    list.innerHTML = rules.map(r => `<div>• match: "${r.match}" → set: "${r.group}" (priority ${r.priority||0})</div>`).join('');
}
document.addEventListener('DOMContentLoaded', function() {
    renderRulesList();
    renderRulesTable();
    // Auto-refresh coverage summary on page load
    refreshCoverageSummary();
});
</script>
</script>
@endsection


