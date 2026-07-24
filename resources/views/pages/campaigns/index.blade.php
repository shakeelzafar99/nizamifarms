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
.camp-status-excluded { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.camp-error-text {
    font-size: 10px;
    color: #dc2626;
    margin-top: 2px;
}
.camp-excluded-reason {
    font-size: 10px;
    color: #92400e;
    margin-top: 2px;
    font-style: italic;
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
.camp-shopify-badge {
    background: #dcfce7;
    color: #166534;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 8px;
    margin-left: 4px;
    border: 1px solid #86efac;
    white-space: nowrap;
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

            {{-- Template-dedup guard. When > 0, customers who already
                 received the selected template in the last N days get
                 inserted as 'skipped' instead of 'pending' so they don't
                 get the same message twice in a short window. Set to 0
                 to turn the guard off and send to everyone regardless
                 of prior sends. --}}
            <div class="camp-form-row" style="margin-top:6px;">
                <div class="camp-form-group" style="flex:1;">
                    <label title="Skip customers who already received the selected template within this many days. 0 disables the check.">
                        🚫 Don't re-send if template sent within (days)
                    </label>
                    <input type="number" id="filterDedupDays" value="30" min="0" max="365" placeholder="30">
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">
                        Uses WhatsApp send history. Set to <b>0</b> to disable.
                    </div>
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

            {{-- Same dedup-window control as the Create modal so the user
                 can refresh the rule when extending an existing campaign.
                 The campaign already knows which template it uses — we
                 just need the window value. --}}
            <div class="camp-form-row" style="margin-top:12px;">
                <div class="camp-form-group" style="flex:1;">
                    <label title="Skip customers who already received this campaign's template within this many days. 0 disables the check.">
                        🚫 Don't re-send if template sent within (days)
                    </label>
                    <input type="number" id="filterDedupDays_addMore" value="30" min="0" max="365" placeholder="30">
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
// Client-side in-tab filters that narrow the currently-loaded campaign
// customer list. Live search across name + phone + city, plus a Shopify
// quick-filter chip. Both reset when switching tabs or campaigns so a user
// never sees a mysteriously-empty list because they forgot they had a
// filter on. Nothing here changes what the backend returns — we only
// hide rows in the DOM — so selection, counts and retry-all are
// unaffected unless we explicitly use the filtered set.
let detailSearch = '';
let detailSourceFilter = 'any'; // 'any' | 'shopify' | 'non_shopify'
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
    detailSearch = '';
    detailSourceFilter = 'any';

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

// Apply the in-tab search + source chip to the currently-loaded
// customer list. Runs purely in the browser over `campaignCustomers`,
// which already reflects the selected status tab, so we never show
// rows that don't belong to the active tab. Search is a case-insensitive
// substring match against name, phone and city — the three things a user
// is most likely to remember. Source chip uses the `is_shopify` flag
// already supplied by the backend.
function getFilteredCampaignCustomers() {
    const src = (campaignCustomers || []);
    const q = (detailSearch || '').trim().toLowerCase();
    const source = detailSourceFilter || 'any';
    if (!q && source === 'any') return src;
    return src.filter(cu => {
        if (source === 'shopify' && !Number(cu.is_shopify)) return false;
        if (source === 'non_shopify' && Number(cu.is_shopify)) return false;
        if (!q) return true;
        const name = ((cu.first_name || '') + ' ' + (cu.last_name || '')).trim().toLowerCase();
        const phone = (cu.phone || cu.phone_normalized || '').toLowerCase();
        const city = (cu.city || '').toLowerCase();
        return name.includes(q) || phone.includes(q) || city.includes(q);
    });
}

function handleDetailSearch(val) {
    detailSearch = val || '';
    renderCampaignDetail();
}

function setDetailSourceFilter(val) {
    detailSourceFilter = val || 'any';
    renderCampaignDetail();
}

function clearDetailFilters() {
    detailSearch = '';
    detailSourceFilter = 'any';
    renderCampaignDetail();
}

function renderCampaignDetail() {
    const el = document.getElementById('campDetail');
    if (!activeCampaign) {
        el.innerHTML = '<div class="camp-empty"><i class="ki-filled ki-notification-on" style="font-size:48px;"></i><p>Select a campaign to view details</p></div>';
        return;
    }

    // NF: preserve the customer-list scroll position across re-renders.
    // Every checkbox toggle calls renderCampaignDetail() which replaces the
    // entire inner HTML of #campDetail — including #campCustomersList — so the
    // scroll position was resetting to 0 on every click, yanking the user
    // back to the top. We snapshot the scrollTop here and restore it after
    // the new DOM is in place (see end of function).
    const _prevList = document.getElementById('campCustomersList');
    const _prevScrollTop = _prevList ? _prevList.scrollTop : 0;

    // NF: preserve focus + caret on the in-tab search input. The whole
    // detail panel is re-rendered on every keystroke, so without this the
    // input would lose focus after typing the first character.
    const _prevSearchEl = document.getElementById('campDetailSearch');
    const _searchWasFocused = _prevSearchEl && document.activeElement === _prevSearchEl;
    const _searchCaret = _prevSearchEl ? _prevSearchEl.selectionStart : null;

    const c = activeCampaign;
    const counts = campaignCounts || {};
    const isEnded = c.status === 'ended';
    // "Excluded" is only rendered when there's actually someone to show —
    // stops it from cluttering the tab strip for campaigns created before
    // the dedup feature existed (they'll always have excluded=0).
    const hasExcluded = (counts.excluded || 0) > 0 || customerStatusFilter === 'excluded';
    const filterKeys = ['pending','sent','failed','skipped'];
    if (hasExcluded) filterKeys.push('excluded');
    filterKeys.push('all');
    const filterBtns = filterKeys.map(f => {
        const count = f === 'all' ? (counts.total || 0) : (counts[f] || 0);
        const label = f.charAt(0).toUpperCase() + f.slice(1);
        return `<button class="camp-filter-btn${customerStatusFilter === f ? ' active' : ''}" onclick="loadCampaignDetail(${c.id},'${f}')">${label} (${count})</button>`;
    }).join('');

    let html = `
    <div class="camp-detail-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3>${esc(c.name)} <span class="camp-badge ${c.status}">${c.status}</span></h3>
                ${c.wa_template_name ? `<div class="camp-template-badge" style="margin-top:4px;"><i class="ki-filled ki-message-text" style="font-size:12px;"></i> Template: ${esc(c.wa_template_name)}</div>` : ''}
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="camp-btn camp-btn-secondary" onclick="openStatsModal(${c.id})"><i class="ki-filled ki-chart-line-up-2" style="font-size:14px;"></i> Stats</button>
                ${(!isEnded && (c.dedup_window_days || 0) > 0 && c.wa_template_name) ? `<button class="camp-btn camp-btn-secondary" onclick="refreshDedup()" title="Re-check pending customers against recent template sends from other campaigns. Newly-matching customers move to the Excluded tab; nothing is sent."><i class="ki-filled ki-arrows-circle" style="font-size:14px;"></i> Refresh Dedup</button>` : ''}
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
            ${(counts.excluded || 0) > 0 ? `<div class="camp-stat" style="color:#92400e;">Excluded: <b>${counts.excluded}</b></div>` : ''}
        </div>
    </div>
    <div class="camp-actions-bar">
        ${filterBtns}
    </div>`;

    // Info strip shown only while the Excluded tab is active, so the
    // operator immediately understands why these rows exist and that
    // the system won't try to send to them.
    if (customerStatusFilter === 'excluded') {
        html += `<div style="margin:0 0 10px 0;padding:10px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;line-height:1.5;">
            <b>These customers matched your filter but were not queued</b> because they already received this template recently (shown per-row below). They will <b>not</b> be sent to — this tab is for audit only.
        </div>`;
    }

    // While a chunked send is in-flight we build a short "(45/180)" suffix to
    // reassure the user the batch is still progressing.
    const progressSuffix = bulkProgress
        ? ` (${bulkProgress.done}/${bulkProgress.total})`
        : '';

    if (selectedCustomerIds.length > 0 && customerStatusFilter === 'pending' && !isEnded) {
        html += `
        <div class="camp-bulk-bar">
            <span>${selectedCustomerIds.length} selected</span>
            <button class="camp-btn camp-btn-primary camp-btn-lg" onclick="bulkSend()" ${bulkSending ? 'disabled' : ''}>
                ${bulkSending ? `<span class="camp-spinner"></span> Sending...${progressSuffix}` : `Send (${selectedCustomerIds.length})`}
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
                ${bulkSending ? `<span class="camp-spinner"></span> Retrying...${progressSuffix}` : `Retry All (${campaignCustomers.length})`}
            </button>
            ${selectedCustomerIds.length > 0 ? `
                <span style="margin-left:8px;">${selectedCustomerIds.length} selected</span>
                <button class="camp-btn camp-btn-secondary" onclick="bulkRetry()" ${bulkSending ? 'disabled' : ''}>Retry Selected</button>
                <button class="camp-btn camp-btn-secondary" onclick="clearSelection()">Clear</button>
            ` : ''}
        </div>`;
    }

    html += `<div class="camp-customers-list" id="campCustomersList">`;

    // In-tab toolbar: live search + Shopify source chips. Only shown when
    // the tab actually has customers loaded, so we don't clutter the
    // empty state. Counts shown as "filtered / total" only when a filter
    // is actually narrowing the list, so honest unfiltered counts stay
    // visible the rest of the time.
    const filteredCustomers = getFilteredCampaignCustomers();
    const hasAnyDetailFilter = (detailSearch && detailSearch.trim() !== '') || (detailSourceFilter && detailSourceFilter !== 'any');
    if (campaignCustomers.length > 0) {
        const qEsc = esc(detailSearch || '');
        const chip = (val, label) => `<button type="button" class="camp-filter-btn${detailSourceFilter === val ? ' active' : ''}" style="padding:4px 10px;font-size:12px;" onclick="setDetailSourceFilter('${val}')">${label}</button>`;
        html += `
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding:0 14px;">
            <div style="position:relative;flex:1;min-width:200px;max-width:340px;">
                <input type="text" id="campDetailSearch" value="${qEsc}" oninput="handleDetailSearch(this.value)" placeholder="Search name, phone or city..." style="width:100%;padding:6px 28px 6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
                ${detailSearch ? `<span onclick="handleDetailSearch('')" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;color:#94a3b8;font-size:14px;line-height:1;" title="Clear search">×</span>` : ''}
            </div>
            <div style="display:flex;gap:4px;">
                ${chip('any', 'All')}
                ${chip('shopify', '🛍 Shopify')}
                ${chip('non_shopify', 'Non-Shopify')}
            </div>
            ${hasAnyDetailFilter ? `
                <span style="font-size:11px;color:#64748b;">Showing <b>${filteredCustomers.length}</b> / ${campaignCustomers.length}</span>
                <button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 8px;font-size:11px;" onclick="clearDetailFilters()">Clear</button>
            ` : ''}
        </div>`;
    }

    const selectableTab = (customerStatusFilter === 'pending' || customerStatusFilter === 'failed') && !isEnded;
    if (selectableTab && filteredCustomers.length > 0) {
        // "Select All" targets the currently-visible (filtered) rows so it
        // matches what the user actually sees. When no filter is active
        // this collapses to the previous behaviour (select every row).
        const selectableVisibleIds = filteredCustomers
            .filter(c => c.campaign_status === (customerStatusFilter === 'failed' ? 'failed' : 'pending'))
            .map(c => c.customer_id);
        const allVisibleSelected = selectableVisibleIds.length > 0 && selectableVisibleIds.every(id => selectedCustomerIds.includes(id));
        html += `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:0 14px;">
            <label class="camp-select-all">
                <input type="checkbox" id="selectAllCb" onchange="toggleSelectAll(this.checked)" ${allVisibleSelected ? 'checked' : ''}>
                Select All (${selectableVisibleIds.length})
            </label>
        </div>`;
    }

    if (!filteredCustomers.length) {
        html += hasAnyDetailFilter
            ? `<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No customers match your filters. <a href="#" onclick="clearDetailFilters();return false;" style="color:#7f56d9;">Clear filters</a></div>`
            : '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No customers in this filter</div>';
    } else {
        filteredCustomers.forEach(cu => {
            const name = ((cu.first_name || '') + ' ' + (cu.last_name || '')).trim() || 'Unknown';
            const phone = cu.phone || cu.phone_normalized || '-';
            const isPending = cu.campaign_status === 'pending';
            const isFailed = cu.campaign_status === 'failed';
            const showCheckbox = (isPending || isFailed) && !isEnded;
            const isChecked = selectedCustomerIds.includes(cu.customer_id);

            const replied = !!cu.replied_at;
            // `is_shopify` is the derived column returned by the detail
            // endpoint (EXISTS check against t_crm_prod_order SH-prefix
            // within 300 days OR the `shopify` key in external_customer_ids).
            // It comes back as 0/1 from MySQL, so coerce to boolean.
            const isShopify = !!Number(cu.is_shopify);
            const matchTags = parseMatchTags(cu.match_tags);
            const tagsHtml = matchTags.length
                ? `<div class="camp-match-tags">${matchTags.map(t => `<span class="camp-match-tag ${tagClass(t)}">${esc(t)}</span>`).join('')}</div>`
                : '';
            html += `
            <div class="camp-customer-row">
                ${showCheckbox ? `<input type="checkbox" class="cust-checkbox" ${isChecked ? 'checked' : ''} onchange="toggleCustomer(${cu.customer_id}, this.checked)">` : ''}
                <div class="camp-customer-info">
                    <div class="camp-customer-name">${esc(name)}${replied ? '<span class="camp-reply-badge">Replied</span>' : ''}${isShopify ? '<span class="camp-shopify-badge" title="Customer has ordered via Shopify">🛍 Shopify</span>' : ''}</div>
                    <div class="camp-customer-phone">${esc(phone)}</div>
                    <div class="camp-customer-details">
                        ${cu.city ? `<span>${esc(cu.city)}</span>` : ''}
                        ${cu.total_orders ? `<span>${cu.total_orders} orders</span>` : ''}
                        ${cu.total_spent ? `<span>PKR ${parseFloat(cu.total_spent).toLocaleString()}</span>` : ''}
                    </div>
                    ${tagsHtml}
                    ${(() => {
                        // Dedup-excluded rows carry a 'Excluded:' error_message
                        // prefix set at insert time. Surface it in a softer
                        // amber style so it reads as info, not a hard error
                        // like a failed WhatsApp send would.
                        if (!cu.error_message) return '';
                        const isDedup = typeof cu.error_message === 'string' && cu.error_message.indexOf('Excluded:') === 0;
                        const cls = isDedup ? 'camp-excluded-reason' : 'camp-error-text';
                        return `<div class="${cls}">${esc(cu.error_message)}</div>`;
                    })()}
                </div>
                <div class="camp-customer-actions">
                    ${(() => {
                        // Show the dedicated 'Excluded' badge (amber) for
                        // dedup-excluded rows, and keep the plain 'Skipped'
                        // badge for operator-skipped rows. Both are
                        // campaign_status='skipped' at the DB level.
                        const isDedupExcluded = cu.campaign_status === 'skipped'
                            && typeof cu.error_message === 'string'
                            && cu.error_message.indexOf('Excluded:') === 0;
                        if (isDedupExcluded) {
                            return `<span class="camp-status-badge camp-status-excluded">Excluded</span>`;
                        }
                        return `<span class="camp-status-badge camp-status-${cu.campaign_status}">${cu.campaign_status}</span>`;
                    })()}
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

    // NF: restore scroll position after the re-render. Use the fresh node
    // created above (the old reference is now detached).
    const _newList = document.getElementById('campCustomersList');
    if (_newList && _prevScrollTop) {
        _newList.scrollTop = _prevScrollTop;
    }

    // NF: restore focus + caret on the detail search input so typing
    // feels continuous across the re-render triggered by each keystroke.
    if (_searchWasFocused) {
        const _newSearchEl = document.getElementById('campDetailSearch');
        if (_newSearchEl) {
            _newSearchEl.focus();
            try {
                const pos = _searchCaret != null ? _searchCaret : _newSearchEl.value.length;
                _newSearchEl.setSelectionRange(pos, pos);
            } catch (e) { /* ignore — some browsers don't support on all input types */ }
        }
    }
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
    // Select-all works on the currently-visible (filtered) rows so the
    // user's mental model matches the UI. When no filter is active this
    // is identical to selecting every row in the tab. When a filter is
    // active, only visible rows get (de)selected — any prior selections
    // on now-hidden rows are preserved on-check, and on-uncheck we only
    // remove the visible ids so the rest of the selection survives.
    const targetStatus = customerStatusFilter === 'failed' ? 'failed' : 'pending';
    const visible = getFilteredCampaignCustomers()
        .filter(c => c.campaign_status === targetStatus)
        .map(c => c.customer_id);
    if (checked) {
        const merged = new Set(selectedCustomerIds);
        visible.forEach(id => merged.add(id));
        selectedCustomerIds = Array.from(merged);
    } else {
        const visibleSet = new Set(visible);
        selectedCustomerIds = selectedCustomerIds.filter(id => !visibleSet.has(id));
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

// NF: size of each sub-batch sent to the server. Keep this small enough that
// a single HTTP round-trip finishes well within the PHP/nginx/Cloudflare
// timeout window even when Meta is slow. The server paces the per-customer
// Meta API calls with a small sleep, so at ~200ms/message a 25-row chunk
// finishes in ~5-6s — comfortably inside any proxy/server timeout and gives
// us visible "X of N sent" progress updates between chunks.
const CAMP_BULK_CHUNK_SIZE = 25;

// Progress state — reflected in the Send/Retry button label while a bulk run
// is in flight so the user can see it's still working on large batches.
let bulkProgress = null; // {done, total, sent, failed}

function chunk(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
    return out;
}

// Runs the bulk send through /send-bulk in small sub-batches so long
// campaigns (hundreds/thousands of customers) don't hit request timeouts
// and the user sees progress. Every per-chunk response follows the same
// shape as the single-shot endpoint so we can aggregate safely.
async function runBulkInChunks(ids, includeFailed) {
    const batches = chunk(ids, CAMP_BULK_CHUNK_SIZE);
    // `excluded` counts rows the send-time dedup guard auto-moved to
    // the Excluded tab (customer received this template from another
    // campaign within the dedup window) — NOT the operator-Skipped tab.
    // Aggregated across chunks so the end-of-run summary is accurate.
    const agg = { sent: 0, failed: 0, excluded: 0, errors: [] };
    bulkProgress = { done: 0, total: ids.length, sent: 0, failed: 0 };

    for (let i = 0; i < batches.length; i++) {
        const slice = batches[i];
        try {
            const body = { customer_ids: slice, body_params: ['@{{customer_name}}'] };
            if (includeFailed) body.include_failed = true;
            const data = await apiFetch(`/campaigns/${activeCampaignId}/send-bulk`, {
                method: 'POST',
                body: JSON.stringify(body),
            });
            if (data.success && data.results) {
                agg.sent += (data.results.sent || 0);
                agg.failed += (data.results.failed || 0);
                agg.excluded += (data.results.excluded || 0);
                if (Array.isArray(data.results.errors)) {
                    agg.errors = agg.errors.concat(data.results.errors);
                }
            } else {
                // Whole chunk rejected by server (e.g. no eligible rows) — count
                // the slice as failed so totals still reconcile for the user.
                agg.failed += slice.length;
                if (data.message) agg.errors.push({ error: data.message });
            }
        } catch (e) {
            agg.failed += slice.length;
            agg.errors.push({ error: 'Network error on chunk ' + (i + 1) });
        }

        bulkProgress.done += slice.length;
        bulkProgress.sent = agg.sent;
        bulkProgress.failed = agg.failed;
        // Refresh the detail panel between chunks so the "Sending... (X/N)"
        // label updates. We purposely don't reload counts from the server
        // here — loadCampaignDetail() runs once at the end — because a mid-
        // batch reload would rebuild the selection UI and could double-submit
        // rows the user re-checked while the send is in flight.
        renderCampaignDetail();
    }

    bulkProgress = null;
    return agg;
}

async function bulkSend() {
    if (!activeCampaign?.wa_template_name || selectedCustomerIds.length === 0) return;
    if (!confirm(`Send "${activeCampaign.wa_template_name}" to ${selectedCustomerIds.length} customer(s)?`)) return;

    bulkSending = true;
    renderCampaignDetail();

    const ids = selectedCustomerIds.slice();
    const agg = await runBulkInChunks(ids, false);

    bulkSending = false;
    selectedCustomerIds = [];
    // Surface the dedup-guard action so the operator understands why
    // `sent + failed` may be less than what they picked: the remainder
    // is now sitting in the Excluded tab, not dropped silently.
    const excludedMsg = agg.excluded > 0
        ? `\nExcluded: ${agg.excluded} (already received this template recently — moved to Excluded tab, not sent)`
        : '';
    alert(`Bulk Send Complete\nSent: ${agg.sent}, Failed: ${agg.failed}${excludedMsg}${agg.errors.length ? '\n\nFirst errors:\n' + agg.errors.map(e => e.error).slice(0, 5).join('\n') : ''}`);
    loadCampaignDetail(activeCampaignId, customerStatusFilter);
    loadCampaigns();
}

// Manual re-check of the dedup window for this campaign. Re-evaluates
// every pending customer against the current send history; matches move
// to the Excluded tab (status='skipped' + 'Excluded:' prefix). Same
// behaviour as the send-time guard but without actually sending anything
// — useful when the operator wants to know the true pending count
// before hitting Send on an old campaign.
async function refreshDedup() {
    if (!activeCampaignId) return;
    if (!confirm('Refresh Dedup: re-check pending customers against recent sends. Matches will be moved to the Excluded tab (no messages will be sent). Continue?')) return;

    try {
        const data = await apiFetch(`/campaigns/${activeCampaignId}/refresh-dedup`, { method: 'POST' });
        if (!data.success) {
            alert(data.message || 'Failed to refresh dedup');
            return;
        }
        const moved = data.moved || 0;
        const scanned = data.pending_scanned || 0;
        if (moved === 0 && scanned === 0) {
            alert(data.message || 'No pending customers to re-check.');
        } else if (moved === 0) {
            alert(`Scanned ${scanned} pending customer(s). None needed to be excluded — your list is already up to date.`);
        } else {
            alert(`Scanned ${scanned} pending customer(s).\n${moved} moved to the Excluded tab (already received this template in the last ${data.dedup_window_days} day${data.dedup_window_days === 1 ? '' : 's'}).`);
        }
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } catch (e) {
        alert('Network error while refreshing dedup');
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

    const ids = selectedCustomerIds.slice();
    const agg = await runBulkInChunks(ids, true);

    bulkSending = false;
    selectedCustomerIds = [];
    const exclRetryMsg = agg.excluded > 0
        ? `\nExcluded: ${agg.excluded} (received this template elsewhere recently — moved to Excluded tab)`
        : '';
    alert(`Retry Complete\nSent: ${agg.sent}, Still Failed: ${agg.failed}${exclRetryMsg}${agg.errors.length ? '\n\nFirst errors:\n' + agg.errors.map(e => e.error).slice(0, 5).join('\n') : ''}`);
    loadCampaignDetail(activeCampaignId, customerStatusFilter);
    loadCampaigns();
}

async function retryAllFailed() {
    if (!activeCampaign?.wa_template_name) return;
    const allIds = (campaignCustomers || []).map(c => c.customer_id);
    if (allIds.length === 0) return;
    if (!confirm(`Retry sending "${activeCampaign.wa_template_name}" to all ${allIds.length} failed customer(s)?`)) return;

    bulkSending = true;
    renderCampaignDetail();

    const agg = await runBulkInChunks(allIds, true);

    bulkSending = false;
    selectedCustomerIds = [];
    const exclAllMsg = agg.excluded > 0
        ? `\nExcluded: ${agg.excluded} (received this template elsewhere recently — moved to Excluded tab)`
        : '';
    alert(`Retry All Complete\nSent: ${agg.sent}, Still Failed: ${agg.failed}${exclAllMsg}${agg.errors.length ? '\n\nFirst errors:\n' + agg.errors.map(e => e.error).slice(0, 5).join('\n') : ''}`);
    loadCampaignDetail(activeCampaignId, customerStatusFilter);
    loadCampaigns();
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
    // Reset the template-dedup window to the default 30 days every time
    // we open Create — it's a per-campaign decision, not a session
    // preference. Present as a number input so the user can type 0 to
    // disable the check if they want to force a re-send.
    var dedupEl = document.getElementById('filterDedupDays');
    if (dedupEl) dedupEl.value = '30';
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
            <div class="camp-form-group">
                <label>Source</label>
                <select onchange="updateFilterGroup('${scope}', ${i}, 'source', this.value || null)">
                    <option value=""             ${!g.source                  ? 'selected' : ''}>Any</option>
                    <option value="shopify"      ${g.source === 'shopify'     ? 'selected' : ''}>🛍 Shopify only</option>
                    <option value="non_shopify"  ${g.source === 'non_shopify' ? 'selected' : ''}>Non-Shopify only</option>
                </select>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Shopify = at least one SH-prefixed order in the last 300 days, or a Shopify customer-id on file.</div>
            </div>
            <div class="camp-form-group">
                <label>Mobile app</label>
                <select onchange="updateFilterGroup('${scope}', ${i}, 'mobile_app', this.value || null)">
                    <option value=""            ${!g.mobile_app                    ? 'selected' : ''}>Any</option>
                    <option value="not_on_app"  ${g.mobile_app === 'not_on_app'    ? 'selected' : ''}>Not on app (exclude app users)</option>
                    <option value="on_app"      ${g.mobile_app === 'on_app'        ? 'selected' : ''}>📱 On app only</option>
                </select>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">On app = has placed an order via the mobile app. Use “Not on app” so people already on the app don’t get the message.</div>
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

    // Template-dedup breakdown. Only shown when the backend actually
    // ran a dedup check (i.e. template + window were both provided).
    // The "net will be sent" number is what really matters to the user
    // — it's what ends up as 'pending' after dedup. Excluded customers
    // are added to the campaign but land in a dedicated 'Excluded' tab
    // for audit — they are NOT sent to.
    if (data.already_sent_count > 0) {
        const tpl = data.wa_template_name ? ` <span style="color:#64748b;">(${esc(data.wa_template_name)})</span>` : '';
        html += `<div style="margin-top:8px;padding:8px 10px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;line-height:1.5;">
            <div><b>🚫 ${data.already_sent_count}</b> already received this template in the last <b>${data.dedup_window_days}</b> day${data.dedup_window_days === 1 ? '' : 's'}${tpl}</div>
            <div style="margin-top:2px;"><b>✅ ${data.net_to_send}</b> will be queued to send. The other ${data.already_sent_count} will appear under the <b>Excluded</b> tab (not sent to).</div>
        </div>`;
    } else if (data.dedup_window_days > 0 && data.count > 0 && data.wa_template_name) {
        // Reassurance state — the user picked a template + window but no
        // one matches the recent-send set, so everybody will receive it.
        html += `<div style="margin-top:6px;font-size:11px;color:#059669;">✅ No recent duplicates — all ${data.count} will be queued.</div>`;
    }

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

    // Pass the selected template + dedup window so the backend can tell
    // us how many of the matched customers already received this
    // template recently. Both are optional: without a template, or with
    // window=0, the backend simply returns already_sent_count=0.
    const dedupDays = parseInt(document.getElementById('filterDedupDays')?.value);
    const data = await apiFetch('/campaigns/preview', {
        method: 'POST',
        body: JSON.stringify({
            filters: buildFilters('create'),
            wa_template_name: selectedTemplateName || null,
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
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

    const dedupDays = parseInt(document.getElementById('filterDedupDays')?.value);
    const data = await apiFetch('/campaigns/create', {
        method: 'POST',
        body: JSON.stringify({
            name,
            wa_template_name: selectedTemplateName,
            notes: document.getElementById('campNotes').value.trim(),
            filters: buildFilters('create'),
            tracking_type: document.getElementById('filterTrackingType').value,
            tracking_window_days: parseInt(document.getElementById('filterTrackingDays').value) || 30,
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Create Campaign';

    if (data.success) {
        // Friendly one-liner summarising the dedup outcome. Only shown
        // when dedup actually removed somebody; otherwise we stay silent.
        // excluded_by_dedup customers are NOT in the campaign at all —
        // the campaign's Total already reflects the net count.
        if ((data.excluded_by_dedup || 0) > 0) {
            alert(
                `Campaign created.\n\n` +
                `${data.pending_count} customer${data.pending_count === 1 ? '' : 's'} queued to send.\n` +
                `${data.excluded_by_dedup} moved to the Excluded tab — already received "${selectedTemplateName}" in the last ${data.dedup_window_days} day${data.dedup_window_days === 1 ? '' : 's'} (will not be sent to).`
            );
        }
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
    // Default the dedup window to 30 every time we open, to match the
    // Create Campaign modal and because this is a per-add choice rather
    // than a persistent preference.
    var dedupEl = document.getElementById('filterDedupDays_addMore');
    if (dedupEl) dedupEl.value = '30';
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

    // When adding to an existing campaign the template is fixed by the
    // campaign itself — we look it up from the active-campaigns list so
    // the backend can apply the same dedup check. If the campaign isn't
    // resolvable for any reason we just skip the template param and the
    // preview falls back to plain filter-count.
    const campaign = campaigns.find(c => c.id === addCustomersCampaignId);
    const dedupDays = parseInt(document.getElementById('filterDedupDays_addMore')?.value);
    const data = await apiFetch('/campaigns/preview', {
        method: 'POST',
        body: JSON.stringify({
            filters: buildFilters('addMore'),
            wa_template_name: campaign?.wa_template_name || null,
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
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

    const dedupDays = parseInt(document.getElementById('filterDedupDays_addMore')?.value);
    const data = await apiFetch(`/campaigns/${addCustomersCampaignId}/add-customers`, {
        method: 'POST',
        body: JSON.stringify({
            filters: buildFilters('addMore'),
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Add Customers';

    if (data.success) {
        const skipped = data.already_in_campaign || 0;
        const excluded = data.excluded_count || 0;
        // excluded_by_dedup = "new candidates we dropped from the insert
        // because they already got this campaign's template recently".
        // Distinct from already_in_campaign (= already part of this
        // campaign at any status).
        const dedupExcluded = data.excluded_by_dedup || 0;
        alert(
            `Added ${data.added} new customer${data.added === 1 ? '' : 's'}.` +
            (skipped > 0 ? `\n${skipped} already in this campaign (not inserted).` : '') +
            (excluded > 0 ? `\n${excluded} excluded via earlier campaigns.` : '') +
            (dedupExcluded > 0 ? `\n${dedupExcluded} moved to the Excluded tab — already received this template in the last ${data.dedup_window_days} day${data.dedup_window_days === 1 ? '' : 's'} (will not be sent to).` : '')
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
