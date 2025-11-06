@extends('layouts.app')

@section('title', 'Product Attributes')

@push('custom_css')
<style>
.attributes-page {
    max-width: 100%;
}

.section-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 20px;
}

.section-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-header h2 {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
}

.section-body {
    padding: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
}

@media (min-width: 1024px) {
    .form-grid-cols-4 {
        grid-template-columns: 2fr 2fr 1fr 1.5fr;
    }
}

.input-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.input-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 5px;
}

.input-field {
    padding: 8px 12px;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    transition: all 0.2s;
}

.input-field:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.btn-add-rule {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 8px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    align-self: flex-end;
}

.btn-add-rule:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.rules-table-container {
    background: #f9fafb;
    border-radius: 8px;
    padding: 12px;
    border: 1px solid #e5e7eb;
}

.rules-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.rules-table thead th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 10px 12px;
    font-size: 11px;
    font-weight: 600;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.rules-table thead th:first-child {
    border-radius: 8px 0 0 0;
}

.rules-table thead th:last-child {
    border-radius: 0 8px 0 0;
}

.rules-table tbody tr {
    background: white;
    border-bottom: 1px solid #e5e7eb;
    transition: all 0.2s;
}

.rules-table tbody tr:hover {
    background: #f0f4ff;
    transform: translateX(4px);
}

.rules-table tbody td {
    padding: 10px 12px;
    font-size: 13px;
    color: #374151;
}

.drag-handle {
    cursor: move;
    color: #9ca3af;
    font-size: 18px;
    user-select: none;
}

.btn-remove {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-remove:hover {
    background: #fecaca;
    transform: scale(1.05);
}

.btn-apply {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    padding: 10px 28px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-apply:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px;
    transition: all 0.2s;
}

.stat-card:hover {
    border-color: #667eea;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
}

.stat-icon {
    font-size: 24px;
    margin-bottom: 6px;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    line-height: 1;
    margin-bottom: 3px;
}

.stat-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
}

.coverage-details {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border: 2px solid #bae6fd;
    border-radius: 10px;
    padding: 16px;
}

.coverage-details h4 {
    font-size: 14px;
    font-weight: 600;
    color: #0c4a6e;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.rule-match-item {
    background: white;
    border: 1px solid #e0f2fe;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.2s;
}

.rule-match-item:hover {
    border-color: #0ea5e9;
    transform: translateX(4px);
}

.rule-match-text {
    font-size: 13px;
    color: #0c4a6e;
}

.rule-match-count {
    font-size: 15px;
    font-weight: 700;
    color: #0284c7;
}

.assignments-table-container {
    background: #f9fafb;
    border-radius: 10px;
    padding: 16px;
    border: 1px solid #e5e7eb;
}

.assignments-table {
    width: 100%;
    border-collapse: collapse;
}

.assignments-table thead th {
    background: #f3f4f6;
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    text-align: left;
    border-bottom: 2px solid #d1d5db;
}

.assignments-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
}

.assignments-table tbody td {
    padding: 10px 12px;
    font-size: 13px;
    color: #4b5563;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 12px;
}

.empty-state-text {
    font-size: 14px;
    font-weight: 500;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.category-row {
    cursor: pointer;
}

.category-row:hover {
    background: #f0f4ff !important;
}

.rules-detail-row {
    background: #f9fafb !important;
}

.rules-detail-row td {
    padding: 8px 12px !important;
}

.rule-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 4px;
    font-size: 12px;
}

.rule-item:last-child {
    margin-bottom: 0;
}

.info-banner {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 2px solid #fbbf24;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.info-banner-icon {
    font-size: 20px;
    flex-shrink: 0;
}

.info-banner-text {
    font-size: 13px;
    color: #78350f;
    line-height: 1.5;
}
</style>
@endpush

@section('content')
<div class="attributes-page">
    <!-- Page Header -->
    <div class="container-fixed">
        <div class="flex items-center justify-between pb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                    <i class="ki-filled ki-category text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Category Management</h1>
                    <p class="text-xs text-gray-600 mt-0.5">Automatically organize products by category</p>
                </div>
            </div>
            <a href="{{ route('products.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-black-left"></i>
                Back to Products
            </a>
        </div>

        <!-- Info Banner -->
        <div class="info-banner">
            <div class="info-banner-icon">💡</div>
            <div class="info-banner-text">
                <strong>How it works:</strong> Create rules below to automatically assign categories to your products based on their names. For example, products with "chicken" in the name will be categorized as "Chicken". Rules are applied automatically when you add new products.
            </div>
        </div>

        <!-- Stats Grid - Moved to Top -->
        <div class="stats-grid" id="statsCardsContainer" style="margin-bottom: 20px;">
            <!-- Will be populated by JavaScript -->
        </div>

        <!-- Main Rules Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="ki-filled ki-setting-2 text-2xl"></i>
                <h2>Categorization Rules</h2>
            </div>
            <div class="section-body">
                <!-- Level Selector -->
                <div class="input-group" style="margin-bottom: 16px;">
                    <label class="input-label">
                        <i class="ki-filled ki-category text-purple-600"></i>
                        Select Category Level
                    </label>
                    <select id="rulesAttribute" class="input-field" style="max-width: 300px;" onchange="onChangeLevel(this.value)">
                        <option value="1" {{ ($activeKey ?? 1) == 1 ? 'selected' : '' }}>{{ $labels['1'] }}</option>
                        <option value="2" {{ ($activeKey ?? 1) == 2 ? 'selected' : '' }}>{{ $labels['2'] }}</option>
                        <option value="3" {{ ($activeKey ?? 1) == 3 ? 'selected' : '' }}>{{ $labels['3'] }}</option>
                    </select>
                </div>

                <!-- Permission Notice -->
                @if(!($canEditPriorities ?? false))
                <div style="background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px;">
                    <i class="ki-filled ki-information-2" style="font-size: 20px; color: #f59e0b;"></i>
                    <div style="flex: 1;">
                        <strong style="display: block; margin-bottom: 4px;">View Only Mode</strong>
                        <span style="font-size: 13px;">Only users with the <strong>Taimur</strong> role can add rules, edit categories, or change priority sequence. You can view current categories and their rules.</span>
                    </div>
                </div>
                @endif

                <!-- Add New Rule Form -->
                <div class="form-grid form-grid-cols-4" id="addRuleForm">
                    <div class="input-group">
                        <label class="input-label">
                            <i class="ki-filled ki-search-list text-blue-600"></i>
                            Search Word in Product Name
                        </label>
                        <input type="text" class="input-field" id="newRuleMatch" placeholder="e.g., chicken, mutton, beef" {{ !($canEditPriorities ?? false) ? 'disabled' : '' }}>
                    </div>
                    <div class="input-group">
                        <label class="input-label">
                            <i class="ki-filled ki-tag text-green-600"></i>
                            Category to Assign
                        </label>
                        <select class="input-field" id="newRuleGroupSelect" onchange="handleCategorySelectChange()" {{ !($canEditPriorities ?? false) ? 'disabled' : '' }}>
                            <option value="">-- Select or Add New --</option>
                            @foreach(($existingCategories[(string)$activeKey] ?? []) as $category)
                                <option value="{{ $category }}">{{ $category }}</option>
                            @endforeach
                            <option value="__ADD_NEW__">✏️ Add New...</option>
                        </select>
                        <input type="text" class="input-field" id="newRuleGroupInput" placeholder="Enter new category name" style="display: none; margin-top: 6px;" {{ !($canEditPriorities ?? false) ? 'disabled' : '' }}>
                        <button type="button" class="btn btn-sm btn-light" id="backToSelectBtn" onclick="backToCategorySelect()" style="display: none; margin-top: 6px; font-size: 12px;" {{ !($canEditPriorities ?? false) ? 'disabled' : '' }}>
                            <i class="ki-filled ki-arrow-left"></i> Back to List
                        </button>
                    </div>
                    <div class="input-group">
                        <label class="input-label">
                            <i class="ki-filled ki-sort text-orange-600"></i>
                            Priority
                        </label>
                        <input type="number" class="input-field" id="newRulePriority" value="0" min="0" {{ !($canEditPriorities ?? false) ? 'disabled' : '' }}>
                    </div>
                    <button class="btn-add-rule" type="button" onclick="addRuleFromForm()" {{ !($canEditPriorities ?? false) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                        <i class="ki-filled ki-plus-circle"></i>
                        Add Rule
                    </button>
                </div>

                <!-- Apply Button - Moved Above Table -->
                <div style="margin-top: 20px; display: flex; justify-content: center;">
                    <button class="btn-apply" type="button" onclick="applySavedRulesUI()">
                        <i class="ki-filled ki-check-circle"></i>
                        Apply Rules to All Products
                    </button>
                </div>

                <!-- Categories Table (Consolidated View) -->
                <div style="margin-top: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <i class="ki-filled ki-category text-gray-600" style="font-size: 14px;"></i>
                            <span style="font-size: 13px; color: #6b7280; font-weight: 600;">Categories & Rules (drag categories to reorder)</span>
                        </div>
                        <div id="addRuleSuccess" style="display: none; background: #d1fae5; color: #065f46; padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; animation: slideIn 0.3s ease-out;">
                            ✅ <span id="addRuleSuccessText"></span>
                        </div>
                    </div>
                    <div class="rules-table-container">
                        <table class="rules-table" id="categoriesTable">
                            <thead>
                                <tr>
                                    <th style="width:40px;"></th>
                                    <th style="width:40px;"></th>
                                    <th>Category Name</th>
                                    <th style="width:100px;">Priority</th>
                                    <th style="width:100px;">Rules</th>
                                    <th style="width:120px;">Products</th>
                                    <th style="width:180px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Categories Summary Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="ki-filled ki-chart-line text-2xl"></i>
                <h2>Top Categories (Top 20)</h2>
            </div>
            <div class="section-body">
                <div style="display: flex; justify-content: flex-end; margin-bottom: 12px;">
                    <button class="kt-btn kt-btn-primary kt-btn-sm" type="button" onclick="refreshCoverageSummary()">
                        <i class="ki-filled ki-arrows-circle"></i>
                        Refresh Statistics
                    </button>
                </div>

                <div class="assignments-table-container">
                    @if(($assignStats ?? collect())->isEmpty())
                        <div class="empty-state">
                            <div class="empty-state-icon">📦</div>
                            <div class="empty-state-text">No categories assigned yet</div>
                        </div>
                    @else
                        <table class="assignments-table">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th style="width: 120px; text-align: right;">Products</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($assignStats as $row)
                                <tr>
                                    <td><strong>{{ $row->value }}</strong></td>
                                    <td style="text-align: right; font-weight: 600; color: #667eea;">{{ $row->cnt }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Uncategorized Products Modal -->
<div id="uncategorizedModal" onclick="if(event.target === this) closeUncategorizedModal();" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(0,0,0,0.2);">
        <!-- Modal Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;">
                <span style="color: #f59e0b;">❓</span> Products Without Category
            </h3>
            <button onclick="closeUncategorizedModal()" style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer; padding: 0; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.15s;">
                &times;
            </button>
                        </div>
                        
                        <!-- Modal Content -->
                        <div style="padding: 20px 24px; overflow-y: auto; flex: 1;">
                            <div id="uncategorizedInfo" class="text-sm text-gray-600 mb-3"></div>
                            <div class="overflow-x-auto">
                                <table class="table table-bordered" style="width: 100%; border-collapse: collapse;">
                                    <thead style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                        <tr>
                                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #6b7280;">ID</th>
                                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #6b7280;">Product Name</th>
                                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #6b7280;">Vendor</th>
                                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #6b7280;">Type</th>
                                            <th style="padding: 10px 12px; text-align: center; font-size: 13px; font-weight: 600; color: #6b7280;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="uncategorizedTableBody" style="font-size: 14px;">
                                        <!-- Rows will be inserted here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Modal Footer -->
                        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end;">
                            <button onclick="closeUncategorizedModal()" class="kt-btn kt-btn-light">
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Removed legacy Apply Rules block to keep a single top-only flow -->
    </div>
</div>

<script>
// Store existing categories for dropdown
const existingCategoriesData = @json($existingCategories);

// Store permission flag for editing priorities
const canEditPriorities = @json($canEditPriorities ?? false);

// Handle category dropdown selection
function handleCategorySelectChange() {
    const select = document.getElementById('newRuleGroupSelect');
    const input = document.getElementById('newRuleGroupInput');
    const backBtn = document.getElementById('backToSelectBtn');
    
    if (select.value === '__ADD_NEW__') {
        select.style.display = 'none';
        input.style.display = 'block';
        backBtn.style.display = 'block';
        input.focus();
    }
}

function backToCategorySelect() {
    const select = document.getElementById('newRuleGroupSelect');
    const input = document.getElementById('newRuleGroupInput');
    const backBtn = document.getElementById('backToSelectBtn');
    
    select.style.display = 'block';
    input.style.display = 'none';
    backBtn.style.display = 'none';
    select.value = '';
    input.value = '';
}

// Get category value from either select or input
function getCategoryValue() {
    const select = document.getElementById('newRuleGroupSelect');
    const input = document.getElementById('newRuleGroupInput');
    
    if (input.style.display !== 'none' && input.value.trim()) {
        return input.value.trim();
    }
    return select.value && select.value !== '__ADD_NEW__' ? select.value : '';
}

// Update category dropdown when level changes
function updateCategoryDropdown() {
    const level = document.getElementById('rulesAttribute').value;
    const select = document.getElementById('newRuleGroupSelect');
    const input = document.getElementById('newRuleGroupInput');
    const backBtn = document.getElementById('backToSelectBtn');
    
    // Reset to select mode
    select.style.display = 'block';
    input.style.display = 'none';
    backBtn.style.display = 'none';
    select.value = '';
    input.value = '';
    
    // Rebuild options
    const categories = existingCategoriesData[level] || [];
    select.innerHTML = '<option value="">-- Select or Add New --</option>';
    categories.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat;
        option.textContent = cat;
        select.appendChild(option);
    });
    const addNewOption = document.createElement('option');
    addNewOption.value = '__ADD_NEW__';
    addNewOption.textContent = '✏️ Add New...';
    select.appendChild(addNewOption);
}

// Store rule match counts globally
let ruleMatchCounts = {};

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
    updateCategoryDropdown(); // Update the dropdown options
    if (!rulesState[level] || rulesState[level].length === 0) {
        // Load rules for this level from server
        window.location.href = '{{ route('products.attributes') }}?level=' + level;
    } else {
        renderCategoriesTable(); 
    }
}
async function addRuleFromForm(){
    if (!canEditPriorities) {
        alert('You do not have permission to add rules. Only users with the Taimur role can manage category priorities.');
        return;
    }
    
    const level = document.getElementById('rulesAttribute').value;
    const match = (document.getElementById('newRuleMatch').value || '').trim();
    const group = getCategoryValue(); // Use the new function to get category from either select or input
    let priority = parseInt(document.getElementById('newRulePriority').value || '0', 10) || 0;
    if (!match || !group) { alert('Please enter both Product Name to Search and Category Name.'); return; }
    
    // Smart priority assignment
    rulesState[level] = rulesState[level] || [];
    
    // Check if this category already exists
    const existingCategoryRules = rulesState[level].filter(r => r.group === group);
    
    if (existingCategoryRules.length > 0) {
        // Category exists - use its existing priority or the highest priority in that category
        const categoryPriority = Math.max(...existingCategoryRules.map(r => r.priority || 0));
        priority = categoryPriority || priority; // Use existing category priority
    } else {
        // New category - assign priority based on current sequence
        // If user entered 0, assign it based on position
        if (priority === 0) {
            // Get all unique categories and their priorities
            const categories = {};
            rulesState[level].forEach(r => {
                if (!categories[r.group]) {
                    categories[r.group] = r.priority || 0;
                } else if ((r.priority || 0) > categories[r.group]) {
                    categories[r.group] = r.priority || 0;
                }
            });
            
            const totalCategories = Object.keys(categories).length;
            const basePriority = Math.max(100, totalCategories * 10);
            // New category gets lowest priority (will be at bottom)
            priority = Math.max(0, basePriority - (totalCategories * 10) - 10);
        }
    }
    
    // Add to state
    rulesState[level].push({ match, group, priority });
    
    // Clear inputs
    document.getElementById('newRuleMatch').value='';
    document.getElementById('newRulePriority').value='0'; // Reset priority input
    backToCategorySelect(); // Reset the category input
    
    // Show success notification
    showAddRuleSuccess(match, group, priority);
    
    // Render table
    renderCategoriesTable();
    
    // Automatically save rules
    await saveRulesInternal(level);
    
    // Automatically refresh coverage
    await refreshCoverageSummary();
}

// Show success notification when rule is added
function showAddRuleSuccess(match, group, priority) {
    const successDiv = document.getElementById('addRuleSuccess');
    const successText = document.getElementById('addRuleSuccessText');
    
    successText.textContent = `Rule "${match}" added to category "${group}" (Priority: ${priority})`;
    successDiv.style.display = 'block';
    
    // Hide after 4 seconds
    setTimeout(() => {
        successDiv.style.display = 'none';
    }, 4000);
}
// Internal function to save rules (used by both manual save and auto-save)
async function saveRulesInternal(level){
    // Save the current rules state (don't read from DOM as categories are dynamic)
    const rules = rulesState[level] || [];
    
    try {
        const response = await fetch('{{ route('products.attributes.save_rules') }}', { 
            method: 'POST', 
            headers: { 
                'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                'Accept':'application/json',
                'Content-Type':'application/json' 
            }, 
            body: JSON.stringify({ attribute_key: level, rules }) 
        });
        
        const data = await response.json();
        renderCategoriesTable();
        return data;
    } catch(error) {
        console.error('Error saving rules:', error);
        alert('Error saving rules');
        throw error;
    }
}

// Public function for manual save (if needed)
async function saveRules(){
    const level = parseInt(document.getElementById('rulesAttribute').value,10);
    const data = await saveRulesInternal(level);
    showRuleSummary(data);
    await refreshCoverageSummary();
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
async function removeRule(idx){
    const level = document.getElementById('rulesAttribute').value;
    
    // Remove from state
    rulesState[level].splice(idx,1);
    
    // Render table
    renderRulesTable();
    
    // Automatically save rules
    await saveRulesInternal(level);
    
    // Automatically refresh coverage
    await refreshCoverageSummary();
}
// New consolidated categories view
function renderCategoriesTable(){
    const level = document.getElementById('rulesAttribute').value;
    const tbody = document.querySelector('#categoriesTable tbody');
    tbody.innerHTML = '';
    
    const rules = rulesState[level] || [];
    
    if (rules.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #9ca3af;"><div style="font-size: 48px; margin-bottom: 12px;">📝</div><div style="font-size: 14px; font-weight: 500;">No rules added yet. Create your first rule above!</div></td></tr>';
        return;
    }
    
    // Group rules by category
    const categories = {};
    rules.forEach((r, originalIndex) => {
        if (!categories[r.group]) {
            categories[r.group] = {
                name: r.group,
                priority: r.priority || 0,
                rules: [],
                expanded: false
            };
        }
        // Keep the highest priority for the category
        if ((r.priority || 0) > categories[r.group].priority) {
            categories[r.group].priority = r.priority || 0;
        }
        categories[r.group].rules.push({ match: r.match, priority: r.priority || 0, originalIndex });
    });
    
    // Convert to array and sort by priority (descending)
    const categoriesArray = Object.values(categories).sort((a, b) => b.priority - a.priority);
    
    // Render each category
    categoriesArray.forEach((category, catIndex) => {
        // Get total product count for this category
        let totalProducts = 0;
        category.rules.forEach(rule => {
            const ruleKey = `${rule.match}|||${category.name}`;
            totalProducts += ruleMatchCounts[ruleKey] || 0;
        });
        
        const productCountDisplay = totalProducts > 0 ? 
            `<span style="color: #10b981; font-weight: 700;">${totalProducts}</span>` : 
            '<span style="color: #9ca3af;">0</span>';
        
        // Main category row
        const tr = document.createElement('tr');
        tr.className = 'category-row';
        tr.draggable = canEditPriorities; // Only draggable if user has permission
        tr.dataset.categoryIndex = catIndex;
        tr.dataset.categoryName = category.name;
        tr.dataset.categoryPriority = category.priority;
        
        // Conditionally render Edit/Remove buttons
        const actionButtons = canEditPriorities ? `
            <button type="button" style="background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-right: 8px;" 
                onmouseover="this.style.background='#bfdbfe'" 
                onmouseout="this.style.background='#dbeafe'"
                onclick="editCategoryName('${escapeHtml(category.name).replace(/'/g, "\\'")}')">
                <i class="ki-filled ki-pencil"></i> Edit
            </button>
            <button type="button" class="btn-remove" onclick="deleteCategory('${escapeHtml(category.name).replace(/'/g, "\\'")}')">
                <i class="ki-filled ki-trash"></i> Remove
            </button>
        ` : `
            <span style="color: #9ca3af; font-size: 12px; font-style: italic;">View Only</span>
        `;
        
        tr.innerHTML = `
            <td style="text-align: center;">
                <span class="drag-handle" style="${canEditPriorities ? 'cursor: grab;' : 'cursor: not-allowed; opacity: 0.5;'}" title="${canEditPriorities ? 'Drag to reorder' : 'Only Taimur role can reorder'}">${canEditPriorities ? '⋮⋮' : '🔒'}</span>
            </td>
            <td style="text-align: center;">
                <span onclick="toggleCategoryExpand('${escapeHtml(category.name)}', this)" 
                      style="cursor: pointer; font-size: 20px; transition: transform 0.2s; display: inline-block;"
                      title="Click to ${category.expanded ? 'collapse' : 'expand'} rules">
                    ${category.expanded ? '▼' : '▶'}
                </span>
            </td>
            <td><strong style="font-size: 14px;">${escapeHtml(category.name)}</strong></td>
            <td style="text-align: center;"><span style="background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 6px; font-weight: 600;">${category.priority}</span></td>
            <td style="text-align: center;"><span style="color: #6b7280; font-size: 12px;">${category.rules.length} rule${category.rules.length !== 1 ? 's' : ''}</span></td>
            <td style="text-align: center;">${productCountDisplay}</td>
            <td style="text-align: center;">
                ${actionButtons}
            </td>
        `;
        
        if (canEditPriorities) {
            tr.addEventListener('dragstart', onCategoryDragStart);
            tr.addEventListener('dragover', onDragOver);
            tr.addEventListener('drop', onCategoryDrop);
        }
        tbody.appendChild(tr);
        
        // Rules detail row (initially hidden)
        if (category.expanded) {
            const detailTr = document.createElement('tr');
            detailTr.className = 'rules-detail-row';
            detailTr.id = `rules-detail-${escapeHtml(category.name)}`;
            detailTr.innerHTML = `
                <td colspan="7" style="padding: 16px 50px;">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                        <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">SEARCH WORDS FOR THIS CATEGORY:</div>
                        ${category.rules.map((rule, ruleIdx) => `
                            <div class="rule-item">
                                <span style="flex: 1;"><strong>"${escapeHtml(rule.match)}"</strong></span>
                                <span style="color: #9ca3af; font-size: 11px;">Priority: ${rule.priority}</span>
                                <button type="button" 
                                    onclick="removeRuleFromCategory('${escapeHtml(category.name)}', ${rule.originalIndex})" 
                                    style="background: #fee2e2; color: #dc2626; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; cursor: pointer;">
                                    Remove
                                </button>
                            </div>
                        `).join('')}
                    </div>
                </td>
            `;
            tbody.appendChild(detailTr);
        }
    });
}

// Toggle expand/collapse for category rules
function toggleCategoryExpand(categoryName, element) {
    const level = document.getElementById('rulesAttribute').value;
    const rules = rulesState[level] || [];
    
    // Find if detail row exists
    const detailRow = document.getElementById(`rules-detail-${categoryName}`);
    const arrow = element;
    
    if (detailRow) {
        // Collapse
        detailRow.remove();
        arrow.textContent = '▶';
        arrow.title = 'Click to expand rules';
    } else {
        // Expand
        const categoryRules = rules.filter(r => r.group === categoryName);
        const categoryRow = arrow.closest('tr');
        const newRow = document.createElement('tr');
        newRow.className = 'rules-detail-row';
        newRow.id = `rules-detail-${categoryName}`;
        newRow.innerHTML = `
            <td colspan="7" style="padding: 16px 50px;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">SEARCH WORDS FOR THIS CATEGORY:</div>
                    ${categoryRules.map((rule, ruleIdx) => {
                        const originalIndex = rules.findIndex(r => r.match === rule.match && r.group === rule.group);
                        return `
                            <div class="rule-item">
                                <span style="flex: 1;"><strong>"${escapeHtml(rule.match)}"</strong></span>
                                <span style="color: #9ca3af; font-size: 11px;">Priority: ${rule.priority || 0}</span>
                                <button type="button" 
                                    onclick="removeRuleFromCategory('${escapeHtml(categoryName)}', ${originalIndex})" 
                                    style="background: #fee2e2; color: #dc2626; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; cursor: pointer;">
                                    Remove
                                </button>
                            </div>
                        `;
                    }).join('')}
                </div>
            </td>
        `;
        categoryRow.after(newRow);
        arrow.textContent = '▼';
        arrow.title = 'Click to collapse rules';
    }
}

// Backward compatibility - keep this for old function calls
function renderRulesTable() {
    renderCategoriesTable();
}

// Remove a specific rule from a category
async function removeRuleFromCategory(categoryName, ruleIndex) {
    if (!confirm(`Remove this search word from "${categoryName}"?`)) return;
    
    const level = document.getElementById('rulesAttribute').value;
    rulesState[level].splice(ruleIndex, 1);
    
    renderCategoriesTable();
    await saveRulesInternal(level);
    await refreshCoverageSummary();
}

// Delete entire category (all its rules)
async function deleteCategory(categoryName) {
    if (!canEditPriorities) {
        alert('You do not have permission to delete categories. Only users with the Taimur role can manage category priorities.');
        return;
    }
    
    const level = document.getElementById('rulesAttribute').value;
    const rules = rulesState[level] || [];
    const count = rules.filter(r => r.group === categoryName).length;
    
    if (!confirm(`Delete category "${categoryName}" and all its ${count} rule(s)?`)) return;
    
    rulesState[level] = rules.filter(r => r.group !== categoryName);
    
    renderCategoriesTable();
    await saveRulesInternal(level);
    await refreshCoverageSummary();
}

// Category drag and drop
let dragCategoryIndex = null;
function onCategoryDragStart(e){ 
    dragCategoryIndex = parseInt(e.currentTarget.dataset.categoryIndex,10); 
}

async function onCategoryDrop(e){
    e.preventDefault();
    const level = document.getElementById('rulesAttribute').value;
    const targetCategoryIndex = parseInt(e.currentTarget.dataset.categoryIndex,10);
    
    if (dragCategoryIndex === null || targetCategoryIndex === dragCategoryIndex) return;
    
    // Get current categories sorted by priority
    const rules = rulesState[level] || [];
    const categories = {};
    rules.forEach(r => {
        if (!categories[r.group]) {
            categories[r.group] = { name: r.group, priority: r.priority || 0 };
        }
        if ((r.priority || 0) > categories[r.group].priority) {
            categories[r.group].priority = r.priority || 0;
        }
    });
    
    const categoriesArray = Object.values(categories).sort((a, b) => b.priority - a.priority);
    
    // Move the dragged category to the target position
    const [draggedCategory] = categoriesArray.splice(dragCategoryIndex, 1);
    categoriesArray.splice(targetCategoryIndex, 0, draggedCategory);
    
    // Reassign priorities sequentially based on new order
    // Start from a high number and decrement (higher priority = higher number)
    const totalCategories = categoriesArray.length;
    const basePriority = Math.max(100, totalCategories * 10); // Ensure we have a good starting point
    
    categoriesArray.forEach((category, index) => {
        const newPriority = basePriority - (index * 10); // Decrement by 10 for each position
        
        // Update all rules in this category with the new sequential priority
        rulesState[level].forEach(rule => {
            if (rule.group === category.name) {
                rule.priority = newPriority;
            }
        });
    });
    
    dragCategoryIndex = null;
    
    console.log('Categories reordered with new priorities:', categoriesArray.map((c, i) => ({
        name: c.name,
        priority: basePriority - (i * 10)
    })));
    
    renderCategoriesTable();
    await saveRulesInternal(level);
    await refreshCoverageSummary();
}

// Old drag and drop functions (kept for compatibility)
let dragIndex = null;
function onDragStart(e){ dragIndex = parseInt(e.currentTarget.dataset.index,10); }
function onDragOver(e){ e.preventDefault(); }
async function onDrop(e){
    e.preventDefault();
    const level = document.getElementById('rulesAttribute').value;
    const targetIndex = parseInt(e.currentTarget.dataset.index,10);
    if (dragIndex === null || targetIndex === dragIndex) return;
    
    // Reorder in state
    const arr = rulesState[level] || [];
    const [moved] = arr.splice(dragIndex,1);
    arr.splice(targetIndex,0,moved);
    rulesState[level] = arr; 
    dragIndex = null; 
    
    // Render table
    renderRulesTable();
    
    // Automatically save rules (priority changed due to reordering)
    await saveRulesInternal(level);
    
    // Automatically refresh coverage
    await refreshCoverageSummary();
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

// Store uncategorized products data globally
let uncategorizedProductsData = [];

async function refreshCoverageSummary() {
    const level = parseInt(document.getElementById('rulesAttribute').value, 10);
    const statsContainer = document.getElementById('statsCardsContainer');
    
    // Show loading state
    statsContainer.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 20px; color: #64748b;"><i class="ki-filled ki-arrows-circle" style="font-size: 24px; display: block; margin: 0 auto 8px; animation: spin 1s linear infinite;"></i><p style="margin: 0; font-size: 13px;">Loading statistics...</p></div>';
    
    try {
        const res = await fetch('{{ route('products.attributes.coverage') }}', { 
            method: 'POST', 
            headers: { 
                'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                'Accept': 'application/json',
                'Content-Type': 'application/json' 
            }, 
            body: JSON.stringify({ attribute_key: level }) 
        });
        
        if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
        }
        
        const data = await res.json();
        
        if (data.success && typeof data.summary !== 'undefined') {
            uncategorizedProductsData = data.uncategorized_sample || [];
            
            // Store rule match counts for use in the table
            ruleMatchCounts = {};
            data.summary.forEach(rule => {
                const ruleKey = `${rule.match}|||${rule.group}`;
                ruleMatchCounts[ruleKey] = rule.matching_products || 0;
            });
            
            // Render Stats Cards
            const coverage = data.total_products > 0 ? ((data.categorized_products / data.total_products) * 100).toFixed(1) : 0;
            
            statsContainer.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value">${data.categorized_products}</div>
                    <div class="stat-label">Products Categorized</div>
                </div>
                <div class="stat-card" ${data.uncategorized_products > 0 ? 'style="cursor: pointer; border-color: #f59e0b;"' : ''} ${data.uncategorized_products > 0 ? 'onclick="showUncategorizedModal()"' : ''}>
                    <div class="stat-icon">${data.uncategorized_products > 0 ? '❓' : '🎉'}</div>
                    <div class="stat-value" style="${data.uncategorized_products > 0 ? 'color: #f59e0b;' : 'color: #10b981;'}">${data.uncategorized_products}</div>
                    <div class="stat-label">${data.uncategorized_products > 0 ? 'Without Category (click to view)' : 'Without Category'}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-value">${data.total_products}</div>
                    <div class="stat-label">Total Products</div>
                </div>
                <div class="stat-card" style="border-color: ${coverage >= 75 ? '#10b981' : coverage >= 50 ? '#f59e0b' : '#ef4444'};">
                    <div class="stat-icon">📈</div>
                    <div class="stat-value" style="color: ${coverage >= 75 ? '#10b981' : coverage >= 50 ? '#f59e0b' : '#ef4444'};">${coverage}%</div>
                    <div class="stat-label">Coverage Rate</div>
                </div>
            `;
            
            // Re-render the rules table to show updated product counts
            renderRulesTable();
        } else {
            const errorMsg = data.message || 'Invalid response format';
            console.error('Coverage API error:', data);
            statsContainer.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 20px; color: #ef4444;"><i class="ki-filled ki-information" style="font-size: 32px; display: block; margin: 0 auto 8px;"></i><p style="margin: 0; font-size: 13px;">Error: ${errorMsg}</p></div>`;
        }
    } catch (error) {
        console.error('Coverage refresh failed:', error);
        statsContainer.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 20px; color: #ef4444;"><i class="ki-filled ki-information" style="font-size: 32px; display: block; margin: 0 auto 8px;"></i><p style="margin: 0; font-size: 13px;">Error: ${error.message}</p></div>`;
    }
}

// Add CSS animation for loading spinner
const style = document.createElement('style');
style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
document.head.appendChild(style);

function showUncategorizedModal() {
    console.log('showUncategorizedModal called');
    console.log('uncategorizedProductsData:', uncategorizedProductsData);
    
    if (!uncategorizedProductsData || uncategorizedProductsData.length === 0) {
        console.error('No uncategorized products data available');
        alert('No uncategorized products data available. Please refresh coverage summary first.');
        return;
    }
    
    const modal = document.getElementById('uncategorizedModal');
    const infoDiv = document.getElementById('uncategorizedInfo');
    const tbody = document.getElementById('uncategorizedTableBody');
    
    if (!modal) {
        console.error('Modal element not found');
        return;
    }
    
    console.log('Showing modal with', uncategorizedProductsData.length, 'products');
    
    // Set info message
    const totalCount = uncategorizedProductsData.length;
    if (infoDiv) {
        infoDiv.innerHTML = `Showing <strong>${totalCount}</strong> uncategorized product${totalCount !== 1 ? 's' : ''} ${totalCount >= 20 ? '(limited to top 20, most recent first)' : ''}`;
    }
    
    // Clear existing rows
    if (tbody) {
        tbody.innerHTML = '';
        
        // Add rows
        uncategorizedProductsData.forEach(product => {
            const row = document.createElement('tr');
            row.style.borderBottom = '1px solid #e5e7eb';
            row.innerHTML = `
                <td style="padding: 10px 12px;">#${product.id}</td>
                <td style="padding: 10px 12px; font-weight: 500;">${escapeHtml(product.title || 'N/A')}</td>
                <td style="padding: 10px 12px;">${escapeHtml(product.vendor || 'N/A')}</td>
                <td style="padding: 10px 12px;">${escapeHtml(product.product_type || 'N/A')}</td>
                <td style="padding: 10px 12px; text-align: center;">
                    <a href="/products/${product.id}/edit" target="_blank" class="kt-btn kt-btn-light kt-btn-sm" style="font-size: 12px; padding: 4px 12px;">
                        <i class="ki-filled ki-notepad-edit"></i> Edit
                    </a>
                </td>
            `;
            tbody.appendChild(row);
        });
    }
    
    // Show modal
    modal.style.display = 'flex';
    console.log('Modal displayed');
    
    // Add keyboard listener
    addModalKeyboardListener();
}

// Make function globally accessible
window.showUncategorizedModal = showUncategorizedModal;

function closeUncategorizedModal() {
    const modal = document.getElementById('uncategorizedModal');
    modal.style.display = 'none';
    // Remove keyboard listener
    document.removeEventListener('keydown', uncategorizedModalKeyHandler);
}

// Keyboard handler for modal
function uncategorizedModalKeyHandler(e) {
    if (e.key === 'Escape') {
        closeUncategorizedModal();
    }
}

// Add keyboard listener when modal opens
function addModalKeyboardListener() {
    document.addEventListener('keydown', uncategorizedModalKeyHandler);
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

function loadRulesForLevel(level){
    // Reset entry rows and list; leave saved set in rulesState
    // Note: rulesContainer element removed - no longer needed
    renderRulesTable();
}
function renderRulesList(){
    // This function is deprecated but kept for compatibility
    // Rules are now shown in the draggable table only
    renderRulesTable();
}
// Edit category name function
async function editCategoryName(oldName) {
    if (!canEditPriorities) {
        alert('You do not have permission to edit categories. Only users with the Taimur role can manage category priorities.');
        return;
    }
    
    const level = parseInt(document.getElementById('rulesAttribute').value, 10);
    
    const newName = prompt(`Edit category name:\n\nCurrent: ${oldName}\n\nThis will rename the category for ALL products and rules that use it.`, oldName);
    
    if (!newName || newName === oldName) {
        return; // User cancelled or entered same name
    }
    
    if (newName.trim() === '') {
        alert('Category name cannot be empty');
        return;
    }
    
    // Confirm the action
    if (!confirm(`Are you sure you want to rename "${oldName}" to "${newName}"?\n\nThis will update all products and rules that use this category.`)) {
        return;
    }
    
    try {
        const response = await fetch('{{ route('products.attributes.rename_category') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                attribute_key: level,
                old_name: oldName,
                new_name: newName.trim()
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(`✅ ${data.message}`);
            
            // Update local rules state
            const rules = rulesState[level] || [];
            rules.forEach(rule => {
                if (rule.group === oldName) {
                    rule.group = newName.trim();
                }
            });
            
            // Re-render table and refresh coverage
            renderRulesTable();
            await refreshCoverageSummary();
            
            // Reload page to refresh category dropdown and top categories table
            window.location.reload();
        } else {
            alert(`❌ ${data.message || 'Failed to rename category'}`);
        }
    } catch (error) {
        console.error('Error renaming category:', error);
        alert('❌ An error occurred while renaming the category');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    renderCategoriesTable();
    // Auto-refresh coverage summary on page load
    refreshCoverageSummary();
});
</script>
</script>
@endsection


