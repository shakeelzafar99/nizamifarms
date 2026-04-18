@extends('layouts.app')

@section('title', 'Campaigns')

@push('demo1_css')
<style>
#content { display: flex; flex-direction: column; overflow: hidden; }

.camp-page {
    display: flex;
    flex: 1;
    height: calc(100vh - 130px);
    background: #f0f2f5;
    border: 1px solid #dfe5e7;
    border-radius: 4px;
    overflow: hidden;
    margin: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* Left panel - campaign list */
.camp-list {
    width: 360px;
    min-width: 300px;
    max-width: 400px;
    background: #fff;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
}
.camp-list-header {
    padding: 16px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.camp-list-header h3 { font-size: 16px; font-weight: 600; margin: 0; color: #1a1a1a; }
.camp-list-items {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
}
.camp-card {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.15s;
}
.camp-card:hover { background: #f8fafc; }
.camp-card.active { background: #ede9fe; border-left: 3px solid #7c3aed; }
.camp-card-name {
    font-size: 14px;
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.camp-card-meta {
    font-size: 12px;
    color: #64748b;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.camp-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}
.camp-badge.active { background: #dcfce7; color: #166534; }
.camp-badge.ended { background: #f1f5f9; color: #64748b; }
.camp-template-badge {
    font-size: 11px;
    color: #7c3aed;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Right panel - detail / empty */
.camp-detail {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #fafbfc;
    overflow: hidden;
}
.camp-detail-header {
    padding: 16px 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
}
.camp-detail-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 6px;
    color: #1a1a1a;
}
.camp-detail-stats {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
.camp-stat {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #64748b;
}
.camp-stat b { color: #1a1a1a; }
.camp-actions-bar {
    padding: 10px 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.camp-filter-btn {
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid #e2e8f0;
    background: #fff;
    cursor: pointer;
    transition: all 0.15s;
    color: #334155;
}
.camp-filter-btn:hover { border-color: #7c3aed; color: #7c3aed; }
.camp-filter-btn.active { background: #7c3aed; color: #fff; border-color: #7c3aed; }
.camp-customers-list {
    flex: 1;
    overflow-y: auto;
    padding: 12px 20px;
    scrollbar-width: thin;
}
.camp-customer-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #fff;
    border-radius: 8px;
    margin-bottom: 8px;
    border: 1px solid #e2e8f0;
    transition: all 0.15s;
}
.camp-customer-row:hover { box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.camp-customer-row .cust-checkbox {
    width: 18px; height: 18px; cursor: pointer; accent-color: #7c3aed;
}
.camp-customer-info { flex: 1; min-width: 0; }
.camp-customer-name {
    font-size: 13px;
    font-weight: 600;
    color: #1a1a1a;
}
.camp-customer-phone {
    font-size: 12px;
    color: #64748b;
}
.camp-customer-details {
    font-size: 11px;
    color: #94a3b8;
    display: flex;
    gap: 10px;
}
.camp-customer-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}
.camp-btn {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.camp-btn-primary { background: #25d366; color: #fff; }
.camp-btn-primary:hover { background: #1da851; }
.camp-btn-secondary { background: #f1f5f9; color: #475569; }
.camp-btn-secondary:hover { background: #e2e8f0; }
.camp-btn-danger { background: #fee2e2; color: #dc2626; }
.camp-btn-danger:hover { background: #fecaca; }
.camp-btn-purple { background: #7c3aed; color: #fff; }
.camp-btn-purple:hover { background: #6d28d9; }
.camp-btn-lg { padding: 8px 18px; font-size: 13px; }
.camp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.camp-status-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}
.camp-status-sent { background: #dcfce7; color: #166534; }
.camp-status-failed { background: #fee2e2; color: #dc2626; }
.camp-status-skipped { background: #f1f5f9; color: #64748b; }
.camp-status-pending { background: #fef3c7; color: #92400e; }
.camp-error-text {
    font-size: 10px;
    color: #dc2626;
    margin-top: 2px;
}

/* Empty state */
.camp-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    color: #94a3b8;
}
.camp-empty i { font-size: 48px; margin-bottom: 12px; }
.camp-empty p { font-size: 14px; }

/* Modal */
.camp-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
}
.camp-modal-overlay.open { display: flex; }
.camp-modal {
    background: #fff;
    border-radius: 12px;
    width: 560px;
    max-width: 95vw;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
}
.camp-modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.camp-modal-header h3 { font-size: 16px; font-weight: 600; margin: 0; }
.camp-modal-body { padding: 24px; }
.camp-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
.camp-form-group { margin-bottom: 16px; }
.camp-form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.camp-form-group input,
.camp-form-group select,
.camp-form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    color: #1a1a1a;
    transition: border-color 0.15s;
}
.camp-form-group input:focus,
.camp-form-group select:focus,
.camp-form-group textarea:focus {
    outline: none;
    border-color: #7c3aed;
    box-shadow: 0 0 0 2px rgba(124,58,237,0.1);
}
.camp-form-row {
    display: flex;
    gap: 12px;
}
.camp-form-row .camp-form-group { flex: 1; }
.camp-template-select-btn {
    width: 100%;
    padding: 10px 12px;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    background: #fafbfc;
    cursor: pointer;
    text-align: left;
    font-size: 13px;
    color: #64748b;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.camp-template-select-btn:hover { border-color: #7c3aed; }
.camp-template-select-btn.selected { border-color: #7c3aed; border-style: solid; color: #7c3aed; background: #faf5ff; }
.camp-template-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}
.camp-template-option:hover { background: #f8fafc; }
.camp-template-option.active { background: #ede9fe; }
.camp-template-option-name { font-size: 13px; font-weight: 600; color: #1a1a1a; }
.camp-template-option-body { font-size: 11px; color: #64748b; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.camp-template-option-meta { font-size: 10px; color: #94a3b8; margin-top: 3px; }
.camp-preview-count {
    padding: 10px 14px;
    background: #ede9fe;
    border-radius: 8px;
    font-size: 13px;
    color: #5b21b6;
    font-weight: 500;
    text-align: center;
}

/* Bulk actions bar */
.camp-bulk-bar {
    padding: 8px 20px;
    background: #ede9fe;
    border-bottom: 1px solid #ddd6fe;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: #5b21b6;
}

/* Stats modal */
.camp-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.camp-stats-card {
    padding: 16px;
    background: #f8fafc;
    border-radius: 8px;
    text-align: center;
}
.camp-stats-card .value {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a1a;
}
.camp-stats-card .label {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}

/* Spinner */
.camp-spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.6s linear infinite;
}
.camp-spinner-dark {
    border-color: rgba(0,0,0,.15);
    border-top-color: #7c3aed;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Select all checkbox */
.camp-select-all {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #475569;
    cursor: pointer;
}

/* Filter groups */
.camp-filter-group {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 10px;
    background: #fafbfc;
}
.camp-filter-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.camp-filter-group-title {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
}
.camp-filter-group-count {
    font-size: 11px;
    color: #7c3aed;
    background: #ede9fe;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}
.camp-filter-group-remove {
    background: none;
    border: none;
    color: #dc2626;
    cursor: pointer;
    font-size: 11px;
    padding: 2px 6px;
}
.camp-filter-group-remove:hover {
    text-decoration: underline;
}
.camp-exclude-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    font-size: 12px;
    color: #334155;
    border-radius: 4px;
    cursor: pointer;
}
.camp-exclude-item:hover {
    background: #f1f5f9;
}
.camp-exclude-item input {
    accent-color: #7c3aed;
}
.camp-reply-badge {
    background: #dbeafe;
    color: #1e40af;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 8px;
    margin-left: 4px;
}
.camp-match-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin-top: 4px;
}
.camp-match-tag {
    display: inline-block;
    background: #f5f3ff;
    color: #6d28d9;
    border: 1px solid #e9d5ff;
    font-size: 10px;
    font-weight: 500;
    padding: 1px 7px;
    border-radius: 10px;
    line-height: 1.5;
    white-space: nowrap;
}
.camp-match-tag.qurbani {
    background: #fef3c7;
    color: #92400e;
    border-color: #fde68a;
}
.camp-match-tag.activity {
    background: #dcfce7;
    color: #15803d;
    border-color: #bbf7d0;
}
.camp-match-tag.city {
    background: #dbeafe;
    color: #1e40af;
    border-color: #bfdbfe;
}
</style>
@endpush

@section('content')
<div class="camp-page">
    <!-- LEFT: Campaign list -->
    <div class="camp-list">
        <div class="camp-list-header">
            <h3>Campaigns</h3>
            <button class="camp-btn camp-btn-purple" onclick="openCreateModal()">
                + New
            </button>
        </div>
        <div class="camp-list-items" id="campListItems">
            <div style="text-align: center; padding: 40px;">
                <div class="camp-spinner camp-spinner-dark"></div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Detail -->
    <div class="camp-detail" id="campDetail">
        <div class="camp-empty">
            <i class="ki-filled ki-notification-on" style="font-size:48px;"></i>
            <p>Select a campaign to view details</p>
        </div>
    </div>
</div>

<!-- Create Campaign Modal -->
<div class="camp-modal-overlay" id="createModal">
    <div class="camp-modal">
        <div class="camp-modal-header">
            <h3>New Campaign</h3>
            <button onclick="closeCreateModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body">
            <div class="camp-form-group">
                <label>Campaign Name</label>
                <input type="text" id="campName" placeholder="e.g. Eid Offer 2026">
            </div>

            <div class="camp-form-group">
                <label>WhatsApp Template</label>
                <button type="button" class="camp-template-select-btn" id="templateSelectBtn" onclick="openTemplatePicker()">
                    <span id="templateSelectLabel">Select a template...</span>
                    <i class="ki-filled ki-down" style="font-size:12px;"></i>
                </button>
            </div>

            <div class="camp-form-group">
                <label>Notes (optional)</label>
                <textarea id="campNotes" rows="2" placeholder="Internal notes about this campaign"></textarea>
            </div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h4 style="font-size:13px;font-weight:600;color:#475569;margin:0;">Customer Filter Groups</h4>
                <button type="button" class="camp-btn camp-btn-secondary" onclick="addFilterGroup()" style="padding:4px 10px;font-size:11px;">+ Add Group</button>
            </div>
            <div style="font-size:11px;color:#64748b;margin-bottom:12px;line-height:1.4;">
                Customers who match ANY group will be included (union). Duplicates are removed automatically, so you can combine e.g. "Qurbani 2025" + "Qurbani 2024" + "Active 90 days" without the same person being messaged twice.
            </div>
            <div id="filterGroupsContainer"></div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <h4 style="font-size:13px;font-weight:600;color:#475569;margin-bottom:12px;">Exclude From Earlier Campaigns</h4>
            <div class="camp-form-group">
                <label>Skip customers already sent in these campaigns</label>
                <div id="excludeCampaignsBox" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:120px;overflow-y:auto;background:#fafbfc;">
                    <div style="font-size:12px;color:#94a3b8;padding:6px;">Loading campaigns...</div>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;">Customers who were already <b>sent</b> a message in any of the selected campaigns will be removed from the new list. Useful when you want to message "everyone we haven't already reached".</div>
            </div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <h4 style="font-size:13px;font-weight:600;color:#475569;margin-bottom:12px;">Sort &amp; Tracking</h4>

            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Sort By</label>
                    <select id="filterSortBy">
                        <option value="last_order_date">Last Order Date</option>
                        <option value="total_spent">Total Spent</option>
                        <option value="created_at">Customer Created</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label>Sort Direction</label>
                    <select id="filterSortDir">
                        <option value="desc">Newest First</option>
                        <option value="asc">Oldest First</option>
                    </select>
                </div>
            </div>

            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Tracking Type</label>
                    <select id="filterTrackingType">
                        <option value="general">General</option>
                        <option value="reactivation">Reactivation</option>
                        <option value="promotion">Promotion</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label>Tracking Window (days)</label>
                    <input type="number" id="filterTrackingDays" value="30" min="1" max="365">
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
                <button class="camp-btn camp-btn-secondary" onclick="previewCount()" id="previewBtn">
                    Preview Count
                </button>
                <div id="previewResult" style="display:none;flex:1;"></div>
            </div>
        </div>
        <div class="camp-modal-footer">
            <button class="camp-btn camp-btn-secondary" onclick="closeCreateModal()">Cancel</button>
            <button class="camp-btn camp-btn-purple camp-btn-lg" onclick="createCampaign()" id="createBtn">
                Create Campaign
            </button>
        </div>
    </div>
</div>

<!-- Add Customers To Existing Campaign Modal -->
<div class="camp-modal-overlay" id="addCustomersModal">
    <div class="camp-modal">
        <div class="camp-modal-header">
            <h3 id="addCustomersTitle">Add More Customers</h3>
            <button onclick="closeAddCustomersModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body">
            <div style="font-size:12px;color:#64748b;margin-bottom:14px;line-height:1.4;">
                Build a filter for the customers you want to add. Anyone who's already in this campaign (at any status) will be skipped automatically — only truly new customers will be added as <b>Pending</b>.
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h4 style="font-size:13px;font-weight:600;color:#475569;margin:0;">Filter Groups</h4>
                <button type="button" class="camp-btn camp-btn-secondary" onclick="addFilterGroup('addMore')" style="padding:4px 10px;font-size:11px;">+ Add Group</button>
            </div>
            <div id="filterGroupsContainer_addMore"></div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <h4 style="font-size:13px;font-weight:600;color:#475569;margin-bottom:12px;">Exclude</h4>
            <div class="camp-form-group">
                <label>Skip customers sent in these earlier campaigns</label>
                <div id="excludeCampaignsBox_addMore" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:120px;overflow-y:auto;background:#fafbfc;">
                    <div style="font-size:12px;color:#94a3b8;padding:6px;">Loading campaigns...</div>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
                <button class="camp-btn camp-btn-secondary" onclick="previewAddCustomers()" id="previewAddBtn">
                    Preview Count
                </button>
                <div id="previewAddResult" style="display:none;flex:1;"></div>
            </div>
        </div>
        <div class="camp-modal-footer">
            <button class="camp-btn camp-btn-secondary" onclick="closeAddCustomersModal()">Cancel</button>
            <button class="camp-btn camp-btn-purple camp-btn-lg" onclick="confirmAddCustomers()" id="addCustomersBtn">
                Add Customers
            </button>
        </div>
    </div>
</div>

<!-- Template Picker Modal -->
<div class="camp-modal-overlay" id="templatePickerModal">
    <div class="camp-modal" style="width:480px;">
        <div class="camp-modal-header">
            <h3>Select Template</h3>
            <button onclick="closeTemplatePicker()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" style="padding:0;max-height:400px;overflow-y:auto;">
            <div id="templatePickerList">
                <div style="text-align:center;padding:30px;">
                    <div class="camp-spinner camp-spinner-dark"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Modal -->
<div class="camp-modal-overlay" id="statsModal">
    <div class="camp-modal" style="width:520px;">
        <div class="camp-modal-header">
            <h3>Campaign Stats</h3>
            <button onclick="closeStatsModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" id="statsBody">
            <div style="text-align:center;padding:30px;">
                <div class="camp-spinner camp-spinner-dark"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('demo1_js')
<script>
const csrf = '{{ csrf_token() }}';
let campaigns = [];
let activeCampaignId = null;
let activeCampaign = null;
let campaignCustomers = [];
let campaignCounts = null;
let customerStatusFilter = 'pending';
let selectedCustomerIds = [];
let waTemplates = [];
let selectedTemplateName = '';
let bulkSending = false;

// Filter-group state keyed by modal scope ('create' or 'addMore').
// Each scope holds an array of filter group objects. Each group is a plain
// object like { activity: '30day', qurbani_year: 2025, ... } — empty keys
// mean "no constraint on that axis".
const filterGroups = { create: [ {} ], addMore: [ {} ] };
let qurbaniYears = [];        // cached list of distinct qurbani years for dropdowns
let cityOptions = [];         // cached city list so we can render both modals
let addCustomersCampaignId = null;

function apiFetch(url, opts = {}) {
    const defaults = {
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
    };
    return fetch(url, { ...defaults, ...opts }).then(r => r.json());
}

// ==========================================================================
// DATA LOADING
// ==========================================================================

async function loadCampaigns() {
    const data = await apiFetch('/campaigns/list');
    if (data.success) {
        campaigns = data.campaigns || [];
        renderCampaignList();
    }
}

async function loadCampaignDetail(id, statusFilter) {
    activeCampaignId = id;
    customerStatusFilter = statusFilter || 'pending';
    selectedCustomerIds = [];

    const data = await apiFetch(`/campaigns/${id}?status=${customerStatusFilter}`);
    if (data.success) {
        activeCampaign = data.campaign;
        campaignCustomers = data.customers || [];
        campaignCounts = data.counts;
        renderCampaignDetail();
        renderCampaignList();
    }
}

async function loadTemplates() {
    const data = await apiFetch('/campaigns/templates');
    if (data.success) {
        waTemplates = data.templates || [];
    }
}

async function loadCities() {
    if (cityOptions.length) return;
    const data = await apiFetch('/campaigns/cities');
    if (data.success) {
        cityOptions = data.cities || [];
    }
}

async function loadQurbaniYears() {
    if (qurbaniYears.length) return;
    const data = await apiFetch('/campaigns/qurbani-years');
    if (data.success) {
        qurbaniYears = (data.years || []).map(y => parseInt(y)).filter(y => !!y);
    }
}

// ==========================================================================
// RENDERING
// ==========================================================================

function renderCampaignList() {
    const el = document.getElementById('campListItems');
    if (!campaigns.length) {
        el.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;font-size:13px;">No campaigns yet</div>';
        return;
    }
    let html = '';
    campaigns.forEach(c => {
        const isActive = activeCampaignId == c.id;
        const total = parseInt(c.total_customers || 0);
        const sent = parseInt(c.sent_count || 0);
        const pending = parseInt(c.pending_count || 0);
        const failed = parseInt(c.failed_count || 0);
        html += `
        <div class="camp-card${isActive ? ' active' : ''}" onclick="loadCampaignDetail(${c.id})">
            <div class="camp-card-name">
                ${esc(c.name)}
                <span class="camp-badge ${c.status}">${c.status}</span>
            </div>
            ${c.wa_template_name ? `<div class="camp-template-badge"><i class="ki-filled ki-message-text" style="font-size:12px;"></i> ${esc(c.wa_template_name)}</div>` : ''}
            <div class="camp-card-meta" style="margin-top:6px;">
                <span>Total: ${total}</span>
                <span style="color:#25d366;">Sent: ${sent}</span>
                ${pending > 0 ? `<span style="color:#d97706;">Pending: ${pending}</span>` : ''}
                ${failed > 0 ? `<span style="color:#dc2626;">Failed: ${failed}</span>` : ''}
            </div>
        </div>`;
    });
    el.innerHTML = html;
}

function renderCampaignDetail() {
    const el = document.getElementById('campDetail');
    if (!activeCampaign) {
        el.innerHTML = '<div class="camp-empty"><i class="ki-filled ki-notification-on" style="font-size:48px;"></i><p>Select a campaign to view details</p></div>';
        return;
    }

    const c = activeCampaign;
    const counts = campaignCounts || {};
    const isEnded = c.status === 'ended';
    const filterBtns = ['pending','sent','failed','skipped','all'].map(f => {
        const count = f === 'all' ? (counts.total || 0) : (counts[f] || 0);
        return `<button class="camp-filter-btn${customerStatusFilter === f ? ' active' : ''}" onclick="loadCampaignDetail(${c.id},'${f}')">${f.charAt(0).toUpperCase() + f.slice(1)} (${count})</button>`;
    }).join('');

    let html = `
    <div class="camp-detail-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3>${esc(c.name)} <span class="camp-badge ${c.status}">${c.status}</span></h3>
                ${c.wa_template_name ? `<div class="camp-template-badge" style="margin-top:4px;"><i class="ki-filled ki-message-text" style="font-size:12px;"></i> Template: ${esc(c.wa_template_name)}</div>` : ''}
            </div>
            <div style="display:flex;gap:8px;">
                <button class="camp-btn camp-btn-secondary" onclick="openStatsModal(${c.id})"><i class="ki-filled ki-chart-line-up-2" style="font-size:14px;"></i> Stats</button>
                ${!isEnded ? `<button class="camp-btn camp-btn-purple" onclick="openAddCustomersModal(${c.id}, '${esc(c.name).replace(/'/g, "\\'")}')">+ Add Customers</button>` : ''}
                ${!isEnded ? `<button class="camp-btn camp-btn-danger" onclick="endCampaign(${c.id})">End Campaign</button>` : ''}
            </div>
        </div>
        <div class="camp-detail-stats" style="margin-top:10px;">
            <div class="camp-stat">Total: <b>${counts.total || 0}</b></div>
            <div class="camp-stat" style="color:#25d366;">Sent: <b>${counts.sent || 0}</b></div>
            <div class="camp-stat" style="color:#d97706;">Pending: <b>${counts.pending || 0}</b></div>
            <div class="camp-stat" style="color:#dc2626;">Failed: <b>${counts.failed || 0}</b></div>
            <div class="camp-stat">Skipped: <b>${counts.skipped || 0}</b></div>
        </div>
    </div>
    <div class="camp-actions-bar">
        ${filterBtns}
    </div>`;

    if (selectedCustomerIds.length > 0 && customerStatusFilter === 'pending' && !isEnded) {
        html += `
        <div class="camp-bulk-bar">
            <span>${selectedCustomerIds.length} selected</span>
            <button class="camp-btn camp-btn-primary camp-btn-lg" onclick="bulkSend()" ${bulkSending ? 'disabled' : ''}>
                ${bulkSending ? '<span class="camp-spinner"></span> Sending...' : `Send (${selectedCustomerIds.length})`}
            </button>
            <button class="camp-btn camp-btn-secondary" onclick="clearSelection()">Clear</button>
        </div>`;
    }

    // Bulk-retry controls for the Failed tab: "Retry All (N)" always available, plus a
    // selection-aware "Retry Selected" bar when the user has picked a subset of failed rows.
    if (customerStatusFilter === 'failed' && !isEnded && campaignCustomers.length > 0) {
        html += `
        <div class="camp-bulk-bar">
            <span>${campaignCustomers.length} failed</span>
            <button class="camp-btn camp-btn-primary camp-btn-lg" onclick="retryAllFailed()" ${bulkSending ? 'disabled' : ''}>
                ${bulkSending ? '<span class="camp-spinner"></span> Retrying...' : `Retry All (${campaignCustomers.length})`}
            </button>
            ${selectedCustomerIds.length > 0 ? `
                <span style="margin-left:8px;">${selectedCustomerIds.length} selected</span>
                <button class="camp-btn camp-btn-secondary" onclick="bulkRetry()" ${bulkSending ? 'disabled' : ''}>Retry Selected</button>
                <button class="camp-btn camp-btn-secondary" onclick="clearSelection()">Clear</button>
            ` : ''}
        </div>`;
    }

    html += `<div class="camp-customers-list" id="campCustomersList">`;

    const selectableTab = (customerStatusFilter === 'pending' || customerStatusFilter === 'failed') && !isEnded;
    if (selectableTab && campaignCustomers.length > 0) {
        html += `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:0 14px;">
            <label class="camp-select-all">
                <input type="checkbox" id="selectAllCb" onchange="toggleSelectAll(this.checked)" ${selectedCustomerIds.length === campaignCustomers.length && campaignCustomers.length > 0 ? 'checked' : ''}>
                Select All (${campaignCustomers.length})
            </label>
        </div>`;
    }

    if (!campaignCustomers.length) {
        html += '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No customers in this filter</div>';
    } else {
        campaignCustomers.forEach(cu => {
            const name = ((cu.first_name || '') + ' ' + (cu.last_name || '')).trim() || 'Unknown';
            const phone = cu.phone || cu.phone_normalized || '-';
            const isPending = cu.campaign_status === 'pending';
            const isFailed = cu.campaign_status === 'failed';
            const showCheckbox = (isPending || isFailed) && !isEnded;
            const isChecked = selectedCustomerIds.includes(cu.customer_id);

            const replied = !!cu.replied_at;
            const matchTags = parseMatchTags(cu.match_tags);
            const tagsHtml = matchTags.length
                ? `<div class="camp-match-tags">${matchTags.map(t => `<span class="camp-match-tag ${tagClass(t)}">${esc(t)}</span>`).join('')}</div>`
                : '';
            html += `
            <div class="camp-customer-row">
                ${showCheckbox ? `<input type="checkbox" class="cust-checkbox" ${isChecked ? 'checked' : ''} onchange="toggleCustomer(${cu.customer_id}, this.checked)">` : ''}
                <div class="camp-customer-info">
                    <div class="camp-customer-name">${esc(name)}${replied ? '<span class="camp-reply-badge">Replied</span>' : ''}</div>
                    <div class="camp-customer-phone">${esc(phone)}</div>
                    <div class="camp-customer-details">
                        ${cu.city ? `<span>${esc(cu.city)}</span>` : ''}
                        ${cu.total_orders ? `<span>${cu.total_orders} orders</span>` : ''}
                        ${cu.total_spent ? `<span>PKR ${parseFloat(cu.total_spent).toLocaleString()}</span>` : ''}
                    </div>
                    ${tagsHtml}
                    ${cu.error_message ? `<div class="camp-error-text">${esc(cu.error_message)}</div>` : ''}
                </div>
                <div class="camp-customer-actions">
                    <span class="camp-status-badge camp-status-${cu.campaign_status}">${cu.campaign_status}</span>
                    ${isPending && !isEnded ? `
                        <button class="camp-btn camp-btn-primary" onclick="sendSingle(${cu.customer_id}, '${esc(name)}')">Send</button>
                        <button class="camp-btn camp-btn-secondary" onclick="skipCustomer(${cu.customer_id})">Skip</button>
                    ` : ''}
                    ${isFailed && !isEnded ? `
                        <button class="camp-btn camp-btn-primary" onclick="retrySingle(${cu.customer_id}, '${esc(name)}')">Retry</button>
                        <button class="camp-btn camp-btn-secondary" onclick="skipCustomer(${cu.customer_id})">Skip</button>
                    ` : ''}
                </div>
            </div>`;
        });
    }

    html += '</div>';
    el.innerHTML = html;
}

// ==========================================================================
// ACTIONS
// ==========================================================================

function toggleCustomer(customerId, checked) {
    if (checked) {
        if (!selectedCustomerIds.includes(customerId)) selectedCustomerIds.push(customerId);
    } else {
        selectedCustomerIds = selectedCustomerIds.filter(id => id !== customerId);
    }
    renderCampaignDetail();
}

function toggleSelectAll(checked) {
    if (checked) {
        // In the Failed tab select all failed rows; everywhere else stick to pending rows.
        const targetStatus = customerStatusFilter === 'failed' ? 'failed' : 'pending';
        selectedCustomerIds = campaignCustomers.filter(c => c.campaign_status === targetStatus).map(c => c.customer_id);
    } else {
        selectedCustomerIds = [];
    }
    renderCampaignDetail();
}

function clearSelection() {
    selectedCustomerIds = [];
    renderCampaignDetail();
}

async function sendSingle(customerId, name) {
    if (!activeCampaign?.wa_template_name) {
        alert('This campaign has no WhatsApp template configured.');
        return;
    }
    if (!confirm(`Send "${activeCampaign.wa_template_name}" to ${name}?`)) return;

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send-bulk`, {
        method: 'POST',
        body: JSON.stringify({ customer_ids: [customerId], body_params: ['@{{customer_name}}'] })
    });

    if (data.success) {
        const r = data.results;
        if (r.failed > 0) {
            alert('Failed: ' + (r.errors?.[0]?.error || 'Unknown error'));
        }
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Failed to send');
    }
}

async function bulkSend() {
    if (!activeCampaign?.wa_template_name || selectedCustomerIds.length === 0) return;
    if (!confirm(`Send "${activeCampaign.wa_template_name}" to ${selectedCustomerIds.length} customer(s)?`)) return;

    bulkSending = true;
    renderCampaignDetail();

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send-bulk`, {
        method: 'POST',
        body: JSON.stringify({ customer_ids: selectedCustomerIds, body_params: ['@{{customer_name}}'] })
    });

    bulkSending = false;

    if (data.success) {
        const r = data.results;
        selectedCustomerIds = [];
        alert(`Bulk Send Complete\nSent: ${r.sent}, Failed: ${r.failed}${r.errors?.length ? '\n\nErrors:\n' + r.errors.map(e => e.error).slice(0, 5).join('\n') : ''}`);
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Bulk send failed');
    }
}

// ======= RETRY (failed rows) =======
async function retrySingle(customerId, name) {
    if (!activeCampaign?.wa_template_name) {
        alert('This campaign has no WhatsApp template configured.');
        return;
    }
    if (!confirm(`Retry sending "${activeCampaign.wa_template_name}" to ${name}?`)) return;

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send-bulk`, {
        method: 'POST',
        body: JSON.stringify({ customer_ids: [customerId], body_params: ['@{{customer_name}}'], include_failed: true })
    });

    if (data.success) {
        const r = data.results;
        if (r.failed > 0) {
            alert('Retry failed: ' + (r.errors?.[0]?.error || 'Unknown error'));
        }
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Failed to retry');
    }
}

async function bulkRetry() {
    if (!activeCampaign?.wa_template_name || selectedCustomerIds.length === 0) return;
    if (!confirm(`Retry sending "${activeCampaign.wa_template_name}" to ${selectedCustomerIds.length} failed customer(s)?`)) return;

    bulkSending = true;
    renderCampaignDetail();

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send-bulk`, {
        method: 'POST',
        body: JSON.stringify({ customer_ids: selectedCustomerIds, body_params: ['@{{customer_name}}'], include_failed: true })
    });

    bulkSending = false;

    if (data.success) {
        const r = data.results;
        selectedCustomerIds = [];
        alert(`Retry Complete\nSent: ${r.sent}, Still Failed: ${r.failed}${r.errors?.length ? '\n\nErrors:\n' + r.errors.map(e => e.error).slice(0, 5).join('\n') : ''}`);
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Bulk retry failed');
    }
}

async function retryAllFailed() {
    if (!activeCampaign?.wa_template_name) return;
    const allIds = (campaignCustomers || []).map(c => c.customer_id);
    if (allIds.length === 0) return;
    if (!confirm(`Retry sending "${activeCampaign.wa_template_name}" to all ${allIds.length} failed customer(s)?`)) return;

    bulkSending = true;
    renderCampaignDetail();

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send-bulk`, {
        method: 'POST',
        body: JSON.stringify({ customer_ids: allIds, body_params: ['@{{customer_name}}'], include_failed: true })
    });

    bulkSending = false;

    if (data.success) {
        const r = data.results;
        selectedCustomerIds = [];
        alert(`Retry All Complete\nSent: ${r.sent}, Still Failed: ${r.failed}${r.errors?.length ? '\n\nErrors:\n' + r.errors.map(e => e.error).slice(0, 5).join('\n') : ''}`);
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Retry all failed');
    }
}

async function skipCustomer(customerId) {
    const data = await apiFetch(`/campaigns/${activeCampaignId}/customers/${customerId}/skip`, {
        method: 'POST'
    });
    if (data.success) {
        campaignCounts = data.counts;
        campaignCustomers = campaignCustomers.filter(c => c.customer_id !== customerId);
        selectedCustomerIds = selectedCustomerIds.filter(id => id !== customerId);
        renderCampaignDetail();
        loadCampaigns();
    }
}

async function endCampaign(id) {
    if (!confirm('End this campaign? This cannot be undone.')) return;
    const data = await apiFetch(`/campaigns/${id}/end`, { method: 'POST' });
    if (data.success) {
        loadCampaignDetail(id, customerStatusFilter);
        loadCampaigns();
    }
}

async function openStatsModal(id) {
    document.getElementById('statsModal').classList.add('open');
    document.getElementById('statsBody').innerHTML = '<div style="text-align:center;padding:30px;"><div class="camp-spinner camp-spinner-dark"></div></div>';

    const data = await apiFetch(`/campaigns/${id}/stats`);
    if (data.success) {
        const s = data.stats;
        let html = `
        <div class="camp-stats-grid">
            <div class="camp-stats-card"><div class="value">${s.total_sent}</div><div class="label">Messages Sent</div></div>
            <div class="camp-stats-card"><div class="value" style="color:#2563eb;">${s.customers_who_replied || 0}</div><div class="label">Replied (${s.reply_rate || 0}%)</div></div>
            <div class="camp-stats-card"><div class="value">${s.customers_who_ordered}</div><div class="label">Ordered (${s.conversion_rate}%)</div></div>
            <div class="camp-stats-card"><div class="value">PKR ${parseFloat(s.total_revenue).toLocaleString()}</div><div class="label">Revenue (${s.tracking_window_days}d window)</div></div>
        </div>`;

        if (s.customer_details?.length) {
            html += '<h4 style="font-size:13px;font-weight:600;margin-bottom:8px;">Customer Breakdown</h4>';
            html += '<div style="max-height:200px;overflow-y:auto;">';
            s.customer_details.forEach(d => {
                const badges = [];
                if (d.replied) badges.push('<span class="camp-reply-badge">Replied</span>');
                html += `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px;align-items:center;">
                    <span>${esc(d.name)} ${badges.join('')}</span>
                    <span>${d.ordered ? `<span style="color:#25d366;">${d.order_count} orders · PKR ${parseFloat(d.revenue).toLocaleString()}</span>` : '<span style="color:#94a3b8;">No orders</span>'}</span>
                </div>`;
            });
            html += '</div>';
        }
        document.getElementById('statsBody').innerHTML = html;
    } else {
        document.getElementById('statsBody').innerHTML = '<p style="text-align:center;color:#dc2626;">Failed to load stats</p>';
    }
}

function closeStatsModal() {
    document.getElementById('statsModal').classList.remove('open');
}

// ==========================================================================
// CREATE CAMPAIGN MODAL
// ==========================================================================

function openCreateModal() {
    resetCreateForm();
    document.getElementById('createModal').classList.add('open');
    renderFilterGroups('create');       // initial render (with whatever cities/years are cached)
    renderExcludeCampaigns('create');
    Promise.all([loadCities(), loadQurbaniYears()]).then(() => {
        renderFilterGroups('create');   // re-render once city + year lists are loaded
    });
    loadTemplates().then(() => {});
}

function closeCreateModal() {
    document.getElementById('createModal').classList.remove('open');
}

function resetCreateForm() {
    document.getElementById('campName').value = '';
    document.getElementById('campNotes').value = '';
    document.getElementById('filterSortBy').value = 'last_order_date';
    document.getElementById('filterSortDir').value = 'desc';
    document.getElementById('filterTrackingType').value = 'general';
    document.getElementById('filterTrackingDays').value = '30';
    document.getElementById('previewResult').style.display = 'none';
    selectedTemplateName = '';
    updateTemplateSelectBtn();
    filterGroups.create = [ {} ];
}

// ==========================================================================
// FILTER GROUPS (shared by create + add-more-customers modals)
// ==========================================================================

function addFilterGroup(scope = 'create') {
    filterGroups[scope].push({});
    renderFilterGroups(scope);
}

function removeFilterGroup(scope, index) {
    filterGroups[scope].splice(index, 1);
    if (filterGroups[scope].length === 0) filterGroups[scope].push({});
    renderFilterGroups(scope);
}

function updateFilterGroup(scope, index, field, value) {
    filterGroups[scope][index] = filterGroups[scope][index] || {};
    if (value === '' || value === null) {
        delete filterGroups[scope][index][field];
    } else {
        filterGroups[scope][index][field] = value;
    }
}

function renderFilterGroups(scope = 'create') {
    const containerId = scope === 'create' ? 'filterGroupsContainer' : 'filterGroupsContainer_addMore';
    const el = document.getElementById(containerId);
    if (!el) return;
    const groups = filterGroups[scope];

    const cityOpts = '<option value="">All Cities</option>' +
        cityOptions.map(c => `<option value="${esc(c.city)}">${esc(c.city)} (${c.count})</option>`).join('');
    const qurbaniOpts = '<option value="">Not a Qurbani filter</option>' +
        qurbaniYears.map(y => `<option value="${y}">Qurbani ${y}</option>`).join('');

    let html = '';
    groups.forEach((g, i) => {
        const removable = groups.length > 1;
        html += `
        <div class="camp-filter-group">
            <div class="camp-filter-group-header">
                <span class="camp-filter-group-title">Group ${i + 1}</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="camp-filter-group-count" id="groupCount_${scope}_${i}" style="display:none;">0</span>
                    ${removable ? `<button type="button" class="camp-filter-group-remove" onclick="removeFilterGroup('${scope}', ${i})">Remove</button>` : ''}
                </div>
            </div>
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Activity</label>
                    <select onchange="updateFilterGroup('${scope}', ${i}, 'activity', this.value)">
                        <option value="" ${!g.activity ? 'selected' : ''}>All Customers</option>
                        <option value="30day" ${g.activity === '30day' ? 'selected' : ''}>Active (30 days)</option>
                        <option value="90day" ${g.activity === '90day' ? 'selected' : ''}>Active (90 days)</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label>City</label>
                    <select onchange="updateFilterGroup('${scope}', ${i}, 'city', this.value)">
                        ${cityOpts.replace(`value="${esc(g.city || '')}"`, `value="${esc(g.city || '')}" selected`)}
                    </select>
                </div>
            </div>
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Min Spend (PKR)</label>
                    <input type="number" value="${g.min_spend != null ? g.min_spend : ''}" placeholder="0" onchange="updateFilterGroup('${scope}', ${i}, 'min_spend', this.value || null)">
                </div>
                <div class="camp-form-group">
                    <label>Max Spend (PKR)</label>
                    <input type="number" value="${g.max_spend != null ? g.max_spend : ''}" placeholder="No limit" onchange="updateFilterGroup('${scope}', ${i}, 'max_spend', this.value || null)">
                </div>
            </div>
            <div class="camp-form-group">
                <label>Qurbani Year</label>
                <select onchange="updateFilterGroup('${scope}', ${i}, 'qurbani_year', this.value ? parseInt(this.value) : null)">
                    ${qurbaniOpts.replace(`value="${g.qurbani_year || ''}"`, `value="${g.qurbani_year || ''}" selected`)}
                </select>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Only customers who placed a Qurbani order in this year (matches the Qurbani Dashboard definition).</div>
            </div>
        </div>`;
    });
    el.innerHTML = html;
}

// ==========================================================================
// EXCLUDE CAMPAIGNS
// ==========================================================================

function renderExcludeCampaigns(scope = 'create') {
    const containerId = scope === 'create' ? 'excludeCampaignsBox' : 'excludeCampaignsBox_addMore';
    const el = document.getElementById(containerId);
    if (!el) return;

    // Don't show the current campaign in its own add-more-customers exclude list.
    const list = campaigns.filter(c => !(scope === 'addMore' && c.id === addCustomersCampaignId));
    if (!list.length) {
        el.innerHTML = '<div style="font-size:12px;color:#94a3b8;padding:6px;">No earlier campaigns yet.</div>';
        return;
    }

    let html = '';
    list.forEach(c => {
        const sent = parseInt(c.sent_count || 0);
        const dt = c.created_at ? new Date(c.created_at).toLocaleDateString() : '';
        html += `
        <label class="camp-exclude-item">
            <input type="checkbox" value="${c.id}" data-scope="${scope}" data-exclude="1">
            <span style="flex:1;">${esc(c.name)} <span style="color:#94a3b8;font-size:11px;">— ${sent} sent · ${dt}</span></span>
        </label>`;
    });
    el.innerHTML = html;
}

function getExcludeCampaignIds(scope = 'create') {
    const containerId = scope === 'create' ? 'excludeCampaignsBox' : 'excludeCampaignsBox_addMore';
    const el = document.getElementById(containerId);
    if (!el) return [];
    return Array.from(el.querySelectorAll('input[type="checkbox"]:checked')).map(cb => parseInt(cb.value));
}

// ==========================================================================
// BUILD PAYLOAD / PREVIEW / CREATE
// ==========================================================================

function buildFilters(scope = 'create') {
    // Strip empty groups so an empty-by-default group doesn't act as "all customers".
    const groups = filterGroups[scope].filter(g => Object.values(g).some(v => v !== null && v !== '' && v !== undefined));

    const payload = {
        // If the user left every group empty we send a single empty group
        // which the backend interprets as "all customers" (same as legacy).
        groups: groups.length ? groups : [ {} ],
        exclude_campaign_ids: getExcludeCampaignIds(scope),
    };

    if (scope === 'create') {
        payload.sort_by = document.getElementById('filterSortBy').value;
        payload.sort_dir = document.getElementById('filterSortDir').value;
    }

    return payload;
}

function renderPreviewResult(resEl, data, scope = 'create') {
    const pieces = [`<b>${data.count}</b> unique customer${data.count === 1 ? '' : 's'} match`];
    if (data.excluded_count > 0) pieces.push(`${data.excluded_count} excluded`);
    let html = `<div class="camp-preview-count">${pieces.join(' · ')}</div>`;

    // Show per-group counts as a tiny breakdown
    const groups = filterGroups[scope];
    if (data.group_counts && groups && groups.length > 0) {
        html += '<div style="font-size:11px;color:#64748b;margin-top:6px;text-align:center;">';
        html += (data.group_counts || []).map((n, i) => {
            const groupExists = groups[i];
            if (!groupExists) return null;
            return `Group ${i + 1}: ${n}`;
        }).filter(Boolean).join(' · ');
        html += '</div>';
        // Also paint the chip on each group card
        (data.group_counts || []).forEach((n, i) => {
            const chip = document.getElementById(`groupCount_${scope}_${i}`);
            if (chip) { chip.textContent = n; chip.style.display = 'inline-block'; }
        });
    }
    resEl.style.display = 'block';
    resEl.innerHTML = html;
}

async function previewCount() {
    const btn = document.getElementById('previewBtn');
    const res = document.getElementById('previewResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner camp-spinner-dark"></span>';

    const data = await apiFetch('/campaigns/preview', {
        method: 'POST',
        body: JSON.stringify({ filters: buildFilters('create') })
    });

    btn.disabled = false;
    btn.innerHTML = 'Preview Count';

    if (data.success) renderPreviewResult(res, data, 'create');
}

async function createCampaign() {
    const name = document.getElementById('campName').value.trim();
    if (!name) { alert('Please enter a campaign name'); return; }
    if (!selectedTemplateName) { alert('Please select a WhatsApp template'); return; }

    const btn = document.getElementById('createBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner"></span> Creating...';

    const data = await apiFetch('/campaigns/create', {
        method: 'POST',
        body: JSON.stringify({
            name,
            wa_template_name: selectedTemplateName,
            notes: document.getElementById('campNotes').value.trim(),
            filters: buildFilters('create'),
            tracking_type: document.getElementById('filterTrackingType').value,
            tracking_window_days: parseInt(document.getElementById('filterTrackingDays').value) || 30,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Create Campaign';

    if (data.success) {
        closeCreateModal();
        loadCampaigns();
        loadCampaignDetail(data.campaign_id, 'pending');
    } else {
        alert(data.message || 'Failed to create campaign');
    }
}

// ==========================================================================
// ADD MORE CUSTOMERS TO EXISTING CAMPAIGN
// ==========================================================================

function openAddCustomersModal(campaignId, campaignName) {
    addCustomersCampaignId = campaignId;
    filterGroups.addMore = [ {} ];
    document.getElementById('addCustomersTitle').textContent = `Add More Customers — ${campaignName}`;
    document.getElementById('previewAddResult').style.display = 'none';
    document.getElementById('addCustomersModal').classList.add('open');
    renderFilterGroups('addMore');
    renderExcludeCampaigns('addMore');
    Promise.all([loadCities(), loadQurbaniYears()]).then(() => {
        renderFilterGroups('addMore');
    });
}

function closeAddCustomersModal() {
    document.getElementById('addCustomersModal').classList.remove('open');
    addCustomersCampaignId = null;
}

async function previewAddCustomers() {
    const btn = document.getElementById('previewAddBtn');
    const res = document.getElementById('previewAddResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner camp-spinner-dark"></span>';

    const data = await apiFetch('/campaigns/preview', {
        method: 'POST',
        body: JSON.stringify({ filters: buildFilters('addMore') })
    });

    btn.disabled = false;
    btn.innerHTML = 'Preview Count';

    if (data.success) renderPreviewResult(res, data, 'addMore');
}

async function confirmAddCustomers() {
    if (!addCustomersCampaignId) return;
    const btn = document.getElementById('addCustomersBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner"></span> Adding...';

    const data = await apiFetch(`/campaigns/${addCustomersCampaignId}/add-customers`, {
        method: 'POST',
        body: JSON.stringify({ filters: buildFilters('addMore') })
    });

    btn.disabled = false;
    btn.innerHTML = 'Add Customers';

    if (data.success) {
        const skipped = data.already_in_campaign || 0;
        const excluded = data.excluded_count || 0;
        alert(
            `Added ${data.added} new customer${data.added === 1 ? '' : 's'}.` +
            (skipped > 0 ? `\n${skipped} already in this campaign (skipped).` : '') +
            (excluded > 0 ? `\n${excluded} excluded via earlier campaigns.` : '')
        );
        closeAddCustomersModal();
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Failed to add customers');
    }
}

// ==========================================================================
// TEMPLATE PICKER
// ==========================================================================

function openTemplatePicker() {
    document.getElementById('templatePickerModal').classList.add('open');
    renderTemplatePicker();
}

function closeTemplatePicker() {
    document.getElementById('templatePickerModal').classList.remove('open');
}

function renderTemplatePicker() {
    const el = document.getElementById('templatePickerList');
    if (!waTemplates.length) {
        el.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No approved templates found</div>';
        return;
    }

    let html = '';
    waTemplates.forEach(t => {
        const isActive = selectedTemplateName === t.name;
        html += `
        <div class="camp-template-option${isActive ? ' active' : ''}" onclick="selectTemplate('${esc(t.name)}')">
            <div class="camp-template-option-name">${esc(t.display_name)}</div>
            <div class="camp-template-option-body">${esc(t.body_text || '')}</div>
            <div class="camp-template-option-meta">${esc(t.category)} &middot; ${esc(t.language)} &middot; ${t.variable_count} variable(s)</div>
        </div>`;
    });
    el.innerHTML = html;
}

function selectTemplate(name) {
    selectedTemplateName = name;
    updateTemplateSelectBtn();
    closeTemplatePicker();
}

function updateTemplateSelectBtn() {
    const btn = document.getElementById('templateSelectBtn');
    const label = document.getElementById('templateSelectLabel');
    if (selectedTemplateName) {
        const t = waTemplates.find(t => t.name === selectedTemplateName);
        label.textContent = t ? t.display_name : selectedTemplateName;
        btn.classList.add('selected');
    } else {
        label.textContent = 'Select a template...';
        btn.classList.remove('selected');
    }
}

// ==========================================================================
// UTILS
// ==========================================================================

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Parse the match_tags column (may come back as a JSON string or already an
// array) into a clean array of labels. Safe against nulls / legacy rows.
function parseMatchTags(raw) {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw.filter(Boolean);
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
    } catch (e) {
        return [];
    }
}

// Colour tags by their content so "Qurbani 2025", "90d active", "Lahore"
// are visually distinct at a glance.
function tagClass(label) {
    const s = String(label).toLowerCase();
    if (s.includes('qurbani')) return 'qurbani';
    if (s.includes('active') || s.includes('day')) return 'activity';
    if (/^[a-z\s]+$/i.test(s) && !s.includes('group')) return 'city';
    return '';
}

// Close modals on overlay click
['createModal', 'templatePickerModal', 'statsModal', 'addCustomersModal'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
        }
    });
});

// ==========================================================================
// INIT
// ==========================================================================

loadCampaigns();
</script>
@endpush
