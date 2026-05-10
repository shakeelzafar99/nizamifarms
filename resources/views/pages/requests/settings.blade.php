{{-- resources/views/pages/requests/settings.blade.php --}}

@extends('layouts.app')

@section('title', 'Request Settings')

@section('content')

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        
        <!-- Page Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Request Workflow Settings</h1>
                <p class="text-gray-600 mt-1">Configure approval levels and category requirements</p>
            </div>
            <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-left"></i> Back to Requests
            </a>
        </div>

        <!-- Approval Level Assignments -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Approval Level Assignments</h3>
            </div>
            
            <div class="kt-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Level 1 Approvers -->
                    <div>
                        <h4 class="text-lg font-semibold mb-4 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-blue-500 text-white flex items-center justify-center">1</span>
                            Level 1 Approvers
                        </h4>
                        
                        <div class="mb-4">
                            <select id="level1-role-select" class="kt-select mb-2">
                                <option value="">Select role to add...</option>
                                @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->urole_name }}</option>
                                @endforeach
                            </select>
                            <button onclick="assignRoleToLevel(1)" class="kt-btn kt-btn-sm kt-btn-primary w-full">
                                <i class="ki-filled ki-plus"></i> Add to Level 1
                            </button>
                        </div>

                        <div class="space-y-2">
                            @forelse($level1Roles as $roleLevel)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <span class="font-medium">{{ $roleLevel->role->urole_name }}</span>
                                <button onclick="removeRoleFromLevel({{ $roleLevel->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-danger">
                                    <i class="ki-filled ki-trash"></i>
                                </button>
                            </div>
                            @empty
                            <div class="p-4 bg-blue-50 border border-blue-200 rounded">
                                <p class="text-sm text-blue-800 font-medium mb-2">👆 No Level 1 approvers assigned yet</p>
                                <p class="text-xs text-blue-600">Select a role above and click "Add to Level 1" to start. Level 1 approvers can give first-stage approval to requests.</p>
                            </div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Level 2 Approvers -->
                    <div>
                        <h4 class="text-lg font-semibold mb-4 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-purple-500 text-white flex items-center justify-center">2</span>
                            Level 2 Approvers
                        </h4>
                        
                        <div class="mb-4">
                            <select id="level2-role-select" class="kt-select mb-2">
                                <option value="">Select role to add...</option>
                                @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->urole_name }}</option>
                                @endforeach
                            </select>
                            <button onclick="assignRoleToLevel(2)" class="kt-btn kt-btn-sm kt-btn-primary w-full">
                                <i class="ki-filled ki-plus"></i> Add to Level 2
                            </button>
                        </div>

                        <div class="space-y-2">
                            @forelse($level2Roles as $roleLevel)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <span class="font-medium">{{ $roleLevel->role->urole_name }}</span>
                                <button onclick="removeRoleFromLevel({{ $roleLevel->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-danger">
                                    <i class="ki-filled ki-trash"></i>
                                </button>
                            </div>
                            @empty
                            <div class="p-4 bg-purple-50 border border-purple-200 rounded">
                                <p class="text-sm text-purple-800 font-medium mb-2">👆 No Level 2 approvers assigned yet</p>
                                <p class="text-xs text-purple-600">Level 2 is optional. Assign roles here for requests that need final approval after Level 1. If no roles assigned, only Level 1 approval is required.</p>
                            </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded">
                    <p class="text-sm text-gray-700 mb-2">
                        <strong>✅ Quick Start Guide:</strong>
                    </p>
                    <ol class="text-xs text-gray-600 space-y-1 ml-4 list-decimal">
                        <li><strong>Assign Level 1 approvers</strong> - These roles can approve requests (e.g., Managers, Supervisors)</li>
                        <li><strong>Optionally assign Level 2</strong> - For requests needing final approval (e.g., Admins, Directors)</li>
                        <li><strong>Configure categories below</strong> - Choose which categories need one level vs two levels</li>
                        <li><strong>Done!</strong> Users can now submit and approve requests</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- ======= REQUEST TYPE MANAGEMENT ======= -->
        <div class="kt-card">
            <div class="kt-card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="kt-card-title">Request Types</h3>
                <button onclick="showCreateTypeModal()" class="kt-btn kt-btn-sm kt-btn-primary">
                    <i class="ki-filled ki-plus"></i> Add Request Type
                </button>
            </div>
            <div class="kt-card-body">
                <p class="text-sm text-gray-600 mb-4">Toggle "In Expenses" to control which types appear in the Expense Management screen. Types with a mobile permission code will only be visible to roles that have that permission assigned.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Icon</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">In Expenses</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">BU Type</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Form Type</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Mobile Permission</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($categories as $cat)
                            <tr class="border-b hover:bg-gray-50" id="typeRow-{{ $cat->id }}"
                                data-id="{{ $cat->id }}"
                                data-code="{{ $cat->category_code }}"
                                data-name="{{ $cat->category_name }}"
                                data-desc="{{ $cat->description }}"
                                data-icon="{{ $cat->icon }}"
                                data-show="{{ $cat->show_in_expenses ? '1' : '0' }}"
                                data-bu="{{ $cat->expense_bu_type ?? '' }}"
                                data-form="{{ $cat->form_type ?? 'general' }}"
                                data-perm="{{ $cat->mobile_permission_code ?? '' }}"
                                data-active="{{ $cat->is_active ? '1' : '0' }}"
                                data-seq="{{ $cat->sequence_order }}">
                                <td class="px-3 py-2 text-lg">{{ $cat->icon ?: '?' }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $cat->category_code }}</td>
                                <td class="px-3 py-2 font-medium">{{ $cat->category_name }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ Str::limit($cat->description, 40) }}</td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" class="kt-checkbox" {{ $cat->show_in_expenses ? 'checked' : '' }}
                                           onchange="quickToggle({{ $cat->id }}, 'show_in_expenses', this.checked)" title="Toggle expense management visibility">
                                </td>
                                <td class="px-3 py-2 text-center text-xs">{{ $cat->expense_bu_type ?? '—' }}</td>
                                <td class="px-3 py-2 text-center text-xs">{{ $cat->form_type ?? 'general' }}</td>
                                <td class="px-3 py-2 text-center text-xs font-mono">{{ $cat->mobile_permission_code ?? '—' }}</td>
                                <td class="px-3 py-2 text-center">{!! $cat->is_active ? '<span class="text-green-600">✓</span>' : '<span class="text-red-400">✗</span>' !!}</td>
                                <td class="px-3 py-2 text-center">
                                    <button onclick="editTypeRow({{ $cat->id }})" class="kt-btn kt-btn-xs kt-btn-light">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======= EXPENSE SUB-CATEGORIES (grouped by request type) ======= -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Expense Sub-Categories</h3>
            </div>
            <div class="kt-card-body">
                <p class="text-sm text-gray-600 mb-4">Expense sub-categories (Petrol, Food, etc.) shown when creating expense requests. Each type has its own sub-categories. Expense Reimbursement and Khaas Expense share common sub-categories; other types (Qurbani, etc.) have their own independent sub-categories.</p>

                @php
                    $expenseTypes = $categories->filter(function($c) {
                        $ft = $c->form_type ?? (in_array($c->category_code, ['expense', 'khaas_expense']) ? 'expense' : 'other');
                        return $ft === 'expense';
                    });
                @endphp

                @foreach($expenseTypes as $etype)
                @php
                    $etBuId = null;
                    $etBuType = $etype->expense_bu_type ?? ($etype->category_code === 'khaas_expense' ? 'khaas' : 'nf');
                    foreach ($businessUnits as $bu) {
                        if (($etBuType === 'nf' && $bu->id == 1) || ($etBuType === 'khaas' && $bu->id != 1)) {
                            $etBuId = $bu->id;
                            break;
                        }
                    }
                    if (!$etBuId && $businessUnits->count() > 0) $etBuId = $businessUnits->first()->id;

                    $isOriginalType = in_array($etype->category_code, ['expense', 'khaas_expense']);

                    $typeSpecific = $expenseSubCategories->filter(function($sc) use ($etype) {
                        return ($sc->request_category_code ?? null) === $etype->category_code;
                    });

                    $shared = $isOriginalType
                        ? $expenseSubCategories->filter(function($sc) use ($etBuId) {
                            return ($sc->request_category_code ?? null) === null && ($sc->business_unit_id ?? 1) == $etBuId;
                        })
                        : collect([]);

                    $allForType = $typeSpecific->merge($shared)->sortBy('config_value')->values();
                @endphp
                <div class="mb-5 p-4 border border-gray-200 rounded-lg bg-gray-50">
                    <h4 class="font-semibold text-sm text-gray-800 mb-2 flex items-center gap-2">
                        <span class="text-lg">{{ $etype->icon ?: '📋' }}</span>
                        {{ $etype->category_name }}
                        <span class="text-xs text-gray-400 font-normal">({{ $etBuType === 'khaas' ? 'Khaas' : 'NF' }} BU &middot; {{ $allForType->count() }} sub-categories)</span>
                    </h4>

                    @if($allForType->count() > 0)
                    <div class="flex flex-wrap gap-2 mb-2">
                        @foreach($allForType as $sc)
                        <span class="inline-flex items-center gap-1 px-3 py-1 {{ ($sc->request_category_code ?? null) ? 'bg-amber-50 border-amber-200' : 'bg-gray-100 border-gray-200' }} border rounded-full text-sm" id="subCat-{{ $sc->id }}">
                            {{ $sc->config_value }}
                            <button onclick="deleteSubCategory({{ $sc->id }}, '{{ addslashes($sc->config_value) }}')" class="text-red-400 hover:text-red-600 ml-1" title="Remove">&times;</button>
                        </span>
                        @endforeach
                    </div>
                    @else
                    <p class="text-sm text-gray-400 italic mb-2">No sub-categories yet. Add some below.</p>
                    @endif

                    <div class="mt-2 flex items-center gap-2">
                        <input type="text" id="newSubCat-{{ $etype->category_code }}" placeholder="New sub-category name..." class="kt-input kt-input-sm" style="max-width:220px;">
                        <button onclick="addSubCategoryForType({{ $etBuId }}, '{{ $etype->category_code }}')" class="kt-btn kt-btn-xs kt-btn-primary">+ Add</button>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        <!-- Category Configuration with Integrated Routing -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Category Approval Configuration & Routing</h3>
            </div>
            
            <div class="kt-card-body">
                <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-sm text-gray-700 mb-2">
                        <strong>📍 How Approval Routing Works:</strong>
                    </p>
                    <p class="text-xs text-gray-600">
                        Configure which users should approve each category. You can set specific assignees for L1 and L2, or leave empty to use role-based approval (any user with L1/L2 role can approve).
                    </p>
                </div>

                <div class="space-y-4">
                    @foreach($categories as $category)
                    @php
                        $categoryRouting = $routingRulesByCategory[$category->id] ?? [];
                        $l1Routing = $categoryRouting[1] ?? [];
                        $l2Routing = $categoryRouting[2] ?? [];

                        // Detect whether this category has request-based routing,
                        // ledger-based routing, or both (for display badges).
                        // 1) Look at actual rules (area_type).
                        $hasRequestRouting = false;
                        $hasLedgerRouting = false;

                        foreach ([$l1Routing, $l2Routing] as $levelRows) {
                            foreach ($levelRows as $row) {
                                if (isset($row['area_type'])) {
                                    if ($row['area_type'] === 'request_category') {
                                        $hasRequestRouting = true;
                                    } elseif ($row['area_type'] === 'ledger_transaction') {
                                        $hasLedgerRouting = true;
                                    }
                                }
                            }
                        }

                        // 2) Apply sensible defaults based on category code so the flow
                        //    badge is still meaningful even when there are no explicit rules.
                        $code = $category->category_code;

                        // Pure request-driven categories
                        if (in_array($code, ['leave', 'expense', 'salary_advance'])) {
                            $hasRequestRouting = true;
                        }

                        // Pure ledger-driven categories
                        if (in_array($code, ['employee_deposit', 'vendor_payment', 'account_transfer', 'invoice_approval', 'invoice_adjustment'])) {
                            $hasLedgerRouting = true;
                        }
                    @endphp
                    <div class="border rounded-lg p-4 bg-white hover:bg-gray-50">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h4 class="font-semibold text-lg">{{ $category->category_name }}</h4>
                                <p class="text-sm text-gray-600">{{ $category->description }}</p>

                                @if($hasRequestRouting || $hasLedgerRouting)
                                    <div class="mt-1 flex flex-wrap gap-1 text-xs">
                                        @if($hasRequestRouting)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-green-100 text-green-800 border border-green-200" title="Approvals start as a request (e.g., Leave, Expense, Salary Advance)">
                                            Request flow
                                        </span>
                                        @endif
                                        @if($hasLedgerRouting)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800 border border-indigo-200" title="Approvals happen directly on ledger entries (e.g., Employee Deposit, Vendor Payment, Account Transfer, Online Invoice)">
                                            Ledger flow
                                        </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <button onclick="toggleCategoryDetails({{ $category->id }})" class="kt-btn kt-btn-sm kt-btn-light">
                                <i class="ki-filled ki-down" id="icon-{{ $category->id }}"></i>
                            </button>
                        </div>
                        
                        <!-- Quick View -->
                        <div class="flex gap-4 text-sm">
                            <span class="flex items-center gap-1">
                                <input type="checkbox" 
                                       class="kt-checkbox" 
                                       data-category-id="{{ $category->id }}"
                                       data-field="requires_level_1"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_1 ? 'checked' : '' }}>
                                <label>Requires L1</label>
                            </span>
                            <span class="flex items-center gap-1">
                                <input type="checkbox" 
                                       class="kt-checkbox" 
                                       data-category-id="{{ $category->id }}"
                                       data-field="requires_level_2"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_2 ? 'checked' : '' }}>
                                <label>Requires L2</label>
                            </span>
                        </div>
                        
                        <!-- Detailed Configuration (Hidden by default) -->
                        <div id="details-{{ $category->id }}" class="hidden mt-4 pt-4 border-t">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Level 1 Routing -->
                                <div class="border rounded p-3 bg-blue-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-blue-500 text-white flex items-center justify-center text-xs">L1</span>
                                        Level 1 Approvers
                                    </h5>
                                    <div id="l1-rules-{{ $category->id }}" class="space-y-2">
                                        @php
                                            $l1Rows = count($l1Routing) ? $l1Routing : [['user_id' => null, 'payment_source_account_id' => null]];
                                        @endphp
                                        @foreach($l1Rows as $row)
                                        <div class="flex items-center gap-2 routing-row" data-level="1">
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="user">
                                                    <option value="">Any L1 user (role-based)</option>
                                                    @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                    <option value="{{ $user->id }}"
                                                        {{ isset($row['user_id']) && (int)$row['user_id'] === (int)$user->id ? 'selected' : '' }}>
                                                        {{ $user->fullname ?? $user->name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Payment Source (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="payment_source">
                                                    <option value="">Any payment source</option>
                                                    @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                    <option value="{{ $account->id }}"
                                                        {{ isset($row['payment_source_account_id']) && (int)$row['payment_source_account_id'] === (int)$account->id ? 'selected' : '' }}>
                                                        {{ $account->account_name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button"
                                                    class="kt-btn kt-btn-sm kt-btn-danger mt-6"
                                                    onclick="removeRoutingRow(this)">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="kt-btn kt-btn-xs kt-btn-light mt-2"
                                            onclick="addRoutingRow({{ $category->id }}, 1)">
                                        <i class="ki-filled ki-plus"></i> Add L1 Rule
                                    </button>
                                </div>
                                
                                <!-- Level 2 Routing -->
                                <div class="border rounded p-3 bg-purple-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-purple-500 text-white flex items-center justify-center text-xs">L2</span>
                                        Level 2 Approvers
                                    </h5>
                                    <div id="l2-rules-{{ $category->id }}" class="space-y-2">
                                        @php
                                            $l2Rows = count($l2Routing) ? $l2Routing : [['user_id' => null, 'payment_source_account_id' => null]];
                                        @endphp
                                        @foreach($l2Rows as $row)
                                        <div class="flex items-center gap-2 routing-row" data-level="2">
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="user">
                                                    <option value="">Any L2 user (role-based)</option>
                                                    @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                    <option value="{{ $user->id }}"
                                                        {{ isset($row['user_id']) && (int)$row['user_id'] === (int)$user->id ? 'selected' : '' }}>
                                                        {{ $user->fullname ?? $user->name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Payment Source (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="payment_source">
                                                    <option value="">Any payment source</option>
                                                    @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                    <option value="{{ $account->id }}"
                                                        {{ isset($row['payment_source_account_id']) && (int)$row['payment_source_account_id'] === (int)$account->id ? 'selected' : '' }}>
                                                        {{ $account->account_name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button"
                                                    class="kt-btn kt-btn-sm kt-btn-danger mt-6"
                                                    onclick="removeRoutingRow(this)">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="kt-btn kt-btn-xs kt-btn-light mt-2"
                                            onclick="addRoutingRow({{ $category->id }}, 2)">
                                        <i class="ki-filled ki-plus"></i> Add L2 Rule
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Additional Settings -->
                            <div class="mt-3 pt-3 border-t">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-600">Auto-Approve Threshold (Optional)</label>
                                        <input type="number" 
                                               class="kt-input kt-input-sm w-full" 
                                               data-category-id="{{ $category->id }}"
                                               data-field="auto_approve_threshold"
                                               value="{{ $category->approvalConfig ? $category->approvalConfig->auto_approve_threshold : '' }}"
                                               placeholder="e.g., 5000"
                                               step="0.01"
                                               min="0">
                                        <p class="text-xs text-gray-500 mt-1">Requests below this amount auto-approve</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3 flex justify-end">
                                <button onclick="saveCategoryConfigAndRouting({{ $category->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-primary">
                                    <i class="ki-filled ki-check"></i> Save Configuration
                                </button>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-6 p-4 bg-yellow-50 rounded">
                    <p class="text-sm text-gray-700">
                        <strong>💡 Configuration Tips:</strong><br>
                        • <strong>Role-based (default):</strong> Leave user assignments empty - any L1/L2 user can approve<br>
                        • <strong>User-specific:</strong> Assign specific users for dedicated approval paths<br>
                        • <strong>Payment source filters:</strong> Route based on which account the payment comes from<br>
                        • <strong>Auto-approve:</strong> Set threshold for automatic approval of small amounts
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle category details
function toggleCategoryDetails(categoryId) {
    const details = document.getElementById(`details-${categoryId}`);
    const icon = document.getElementById(`icon-${categoryId}`);
    
    if (details.classList.contains('hidden')) {
        details.classList.remove('hidden');
        icon.classList.remove('ki-down');
        icon.classList.add('ki-up');
    } else {
        details.classList.add('hidden');
        icon.classList.remove('ki-up');
        icon.classList.add('ki-down');
    }
}

// Add a new routing row for a given category and level (1 or 2)
function addRoutingRow(categoryId, level) {
    const container = document.getElementById(`l${level}-rules-${categoryId}`);
    if (!container) return;

    const firstRow = container.querySelector('.routing-row');
    let newRow;

    if (firstRow) {
        newRow = firstRow.cloneNode(true);
        // Reset selects in cloned row
        newRow.querySelectorAll('select').forEach(sel => {
            sel.value = '';
        });
    } else {
        // Fallback: no existing row (should not normally happen)
        newRow = document.createElement('div');
        newRow.className = 'flex items-center gap-2 routing-row';
        newRow.dataset.level = String(level);
        newRow.innerHTML = '<span class="text-xs text-red-600">No template row found</span>';
    }

    container.appendChild(newRow);
}

// Remove a routing row (but keep at least one row for UX)
function removeRoutingRow(button) {
    const row = button.closest('.routing-row');
    if (!row) return;

    const container = row.parentElement;
    const rows = container.querySelectorAll('.routing-row');

    if (rows.length <= 1) {
        // Just clear selections instead of removing the last row
        row.querySelectorAll('select').forEach(sel => {
            sel.value = '';
        });
    } else {
        row.remove();
    }
}

// Save category configuration and routing
function saveCategoryConfigAndRouting(categoryId) {
    // Get basic config
    const requiresL1 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_1"]`).checked;
    const requiresL2 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_2"]`).checked;
    const threshold = document.querySelector(`input[data-category-id="${categoryId}"][data-field="auto_approve_threshold"]`).value;

    // Build routing rules array from UI
    const rules = [];
    [1, 2].forEach(level => {
        const container = document.getElementById(`l${level}-rules-${categoryId}`);
        if (!container) return;

        container.querySelectorAll('.routing-row').forEach(row => {
            const userSelect = row.querySelector('select[data-role="user"]');
            const accountSelect = row.querySelector('select[data-role="payment_source"]');

            if (!userSelect || !userSelect.value) {
                return; // Skip rows without user selected
            }

            const rule = {
                level: level,
                user_id: parseInt(userSelect.value, 10),
                payment_source_account_id: accountSelect && accountSelect.value
                    ? parseInt(accountSelect.value, 10)
                    : null
            };

            rules.push(rule);
        });
    });

    // First, save basic config
    fetch(`/requests/settings/categories/${categoryId}/config`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            requires_level_1: requiresL1,
            requires_level_2: requiresL2,
            auto_approve_threshold: threshold || null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + data.message);
            return;
        }

        // Now save routing rules (this will also clear any existing rules for this category)
        fetch(`/requests/settings/categories/${categoryId}/routing`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ rules })
        })
        .then(response => response.json())
        .then(routingData => {
            if (routingData.success) {
                alert('Configuration and routing saved successfully!');
            } else {
                alert('Configuration saved, but routing failed: ' + routingData.message);
            }
        })
        .catch(error => {
            console.error('Error saving routing rules:', error);
            alert('Configuration saved, but routing had errors. Check console.');
        });
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function assignRoleToLevel(level) {
    const selectId = level === 1 ? 'level1-role-select' : 'level2-role-select';
    const roleId = document.getElementById(selectId).value;
    
    if (!roleId) {
        alert('Please select a role');
        return;
    }
    
    fetch('{{ route("requests.settings.roles.assign") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            role_id: roleId,
            approval_level: level
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Role assigned successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function removeRoleFromLevel(id) {
    if (!confirm('Remove this role from the approval level?')) {
        return;
    }
    
    fetch(`/requests/settings/roles/level/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Role removed successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// ======= REQUEST TYPE MANAGEMENT =======

function quickToggle(catId, field, value) {
    const row = document.getElementById('typeRow-' + catId);
    if (!row) return;

    const payload = {
        category_name: row.dataset.name,
        description: row.dataset.desc || null,
        icon: row.dataset.icon || null,
        color_class: 'bg-gray-500',
        is_active: row.dataset.active === '1',
        sequence_order: parseInt(row.dataset.seq) || 0,
        show_in_expenses: field === 'show_in_expenses' ? value : (row.dataset.show === '1'),
        expense_bu_type: row.dataset.bu || null,
        form_type: row.dataset.form || 'general',
        mobile_permission_code: row.dataset.perm || null,
    };

    fetch('/requests/settings/categories/' + catId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            row.dataset.show = payload.show_in_expenses ? '1' : '0';
        } else {
            alert('Error: ' + data.message);
            location.reload();
        }
    })
    .catch(e => { console.error(e); alert('Failed'); location.reload(); });
}

function showCreateTypeModal() {
    const html = `<div id="typeModal" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
        <div style="background:#fff;border-radius:12px;padding:24px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;">
            <h3 style="font-size:18px;font-weight:600;margin-bottom:16px;">Create New Request Type</h3>
            <div style="display:grid;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Code (lowercase, no spaces) *</label><input id="tm_code" class="kt-input kt-input-sm w-full" placeholder="e.g. qurbani"></div>
                    <div><label class="text-xs text-gray-600">Name *</label><input id="tm_name" class="kt-input kt-input-sm w-full" placeholder="e.g. Qurbani"></div>
                </div>
                <div><label class="text-xs text-gray-600">Description</label><input id="tm_desc" class="kt-input kt-input-sm w-full" placeholder="Short description"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Icon (emoji)</label><input id="tm_icon" class="kt-input kt-input-sm w-full" placeholder="🐄"></div>
                    <div><label class="text-xs text-gray-600">Color Class</label><input id="tm_color" class="kt-input kt-input-sm w-full" value="bg-gray-500" placeholder="bg-amber-500"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Show in Expense Management</label><select id="tm_show_expenses" class="kt-select kt-select-sm w-full"><option value="0">No</option><option value="1">Yes</option></select></div>
                    <div><label class="text-xs text-gray-600">BU Type (for expense filtering)</label><select id="tm_bu_type" class="kt-select kt-select-sm w-full"><option value="">None</option><option value="nf">NF (Nizami Farms)</option><option value="khaas">Khaas</option><option value="all">All BUs</option></select></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Form Type</label><select id="tm_form_type" class="kt-select kt-select-sm w-full"><option value="general">General</option><option value="expense">Expense (amount, category, receipt)</option><option value="salary">Salary (amount only)</option><option value="leave">Leave (date range)</option></select></div>
                    <div><label class="text-xs text-gray-600">Mobile Permission Code</label><input id="tm_perm" class="kt-input kt-input-sm w-full" placeholder="e.g. expense_type_qurbani"></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button onclick="document.getElementById('typeModal').remove()" class="kt-btn kt-btn-sm kt-btn-light">Cancel</button>
                <button onclick="submitCreateType()" class="kt-btn kt-btn-sm kt-btn-primary">Create</button>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}

function submitCreateType() {
    const code = document.getElementById('tm_code').value.trim().toLowerCase().replace(/\s+/g, '_');
    const name = document.getElementById('tm_name').value.trim();
    if (!code || !name) { alert('Code and Name are required'); return; }

    fetch('{{ route("requests.settings.category.create") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({
            category_code: code,
            category_name: name,
            description: document.getElementById('tm_desc').value.trim() || null,
            icon: document.getElementById('tm_icon').value.trim() || null,
            color_class: document.getElementById('tm_color').value.trim() || 'bg-gray-500',
            show_in_expenses: document.getElementById('tm_show_expenses').value === '1',
            expense_bu_type: document.getElementById('tm_bu_type').value || null,
            form_type: document.getElementById('tm_form_type').value || 'general',
            mobile_permission_code: document.getElementById('tm_perm').value.trim() || null,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Request type created!'); location.reload(); }
        else { alert('Error: ' + data.message); }
    })
    .catch(e => { console.error(e); alert('Failed to create.'); });
}

function editTypeRow(catId) {
    const row = document.getElementById('typeRow-' + catId);
    if (!row) return;
    const d = row.dataset;
    const code = d.code;
    const name = d.name;
    const desc = d.desc || '';
    const icon = d.icon || '';
    const showExp = d.show === '1';
    const buType = d.bu || '';
    const formType = d.form || 'general';
    const perm = d.perm || '';
    const active = d.active === '1';
    const seq = d.seq || '0';

    const html = `<div id="typeEditModal" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
        <div style="background:#fff;border-radius:12px;padding:24px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;">
            <h3 style="font-size:18px;font-weight:600;margin-bottom:16px;">Edit: ${name} <span style="font-size:12px;color:#9ca3af;">(${code})</span></h3>
            <div style="display:grid;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Name *</label><input id="te_name" class="kt-input kt-input-sm w-full" value="${name}"></div>
                    <div><label class="text-xs text-gray-600">Icon</label><input id="te_icon" class="kt-input kt-input-sm w-full" value="${icon}"></div>
                </div>
                <div><label class="text-xs text-gray-600">Description</label><input id="te_desc" class="kt-input kt-input-sm w-full" value="${desc}"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Show in Expense Management</label><select id="te_show_expenses" class="kt-select kt-select-sm w-full"><option value="0" ${!showExp?'selected':''}>No</option><option value="1" ${showExp?'selected':''}>Yes</option></select></div>
                    <div><label class="text-xs text-gray-600">BU Type</label><select id="te_bu_type" class="kt-select kt-select-sm w-full"><option value="" ${!buType?'selected':''}>None</option><option value="nf" ${buType==='nf'?'selected':''}>NF</option><option value="khaas" ${buType==='khaas'?'selected':''}>Khaas</option><option value="all" ${buType==='all'?'selected':''}>All</option></select></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Form Type</label><select id="te_form_type" class="kt-select kt-select-sm w-full"><option value="general" ${formType==='general'?'selected':''}>General</option><option value="expense" ${formType==='expense'?'selected':''}>Expense</option><option value="salary" ${formType==='salary'?'selected':''}>Salary</option><option value="leave" ${formType==='leave'?'selected':''}>Leave</option></select></div>
                    <div><label class="text-xs text-gray-600">Mobile Permission</label><input id="te_perm" class="kt-input kt-input-sm w-full" value="${perm}"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Active</label><select id="te_active" class="kt-select kt-select-sm w-full"><option value="1" ${active?'selected':''}>Yes</option><option value="0" ${!active?'selected':''}>No</option></select></div>
                    <div><label class="text-xs text-gray-600">Sequence Order</label><input id="te_seq" type="number" class="kt-input kt-input-sm w-full" value="${seq}" min="0"></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button onclick="document.getElementById('typeEditModal').remove()" class="kt-btn kt-btn-sm kt-btn-light">Cancel</button>
                <button onclick="submitEditType(${catId})" class="kt-btn kt-btn-sm kt-btn-primary">Save</button>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}

function submitEditType(catId) {
    const name = document.getElementById('te_name').value.trim();
    if (!name) { alert('Name is required'); return; }

    fetch('/requests/settings/categories/' + catId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({
            category_name: name,
            description: document.getElementById('te_desc').value.trim() || null,
            icon: document.getElementById('te_icon').value.trim() || null,
            color_class: 'bg-gray-500',
            is_active: document.getElementById('te_active').value === '1',
            sequence_order: parseInt(document.getElementById('te_seq').value) || 0,
            show_in_expenses: document.getElementById('te_show_expenses').value === '1',
            expense_bu_type: document.getElementById('te_bu_type').value || null,
            form_type: document.getElementById('te_form_type').value || 'general',
            mobile_permission_code: document.getElementById('te_perm').value.trim() || null,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Updated!'); location.reload(); }
        else { alert('Error: ' + data.message); }
    })
    .catch(e => { console.error(e); alert('Failed to update.'); });
}

// ======= EXPENSE SUB-CATEGORIES =======

function addSubCategoryForType(buId, requestCategoryCode) {
    const inputId = requestCategoryCode ? 'newSubCat-' + requestCategoryCode : 'newSubCat-shared-' + buId;
    const input = document.getElementById(inputId);
    if (!input) { alert('Input not found'); return; }
    const name = input.value.trim();
    if (!name) { alert('Enter a sub-category name'); return; }

    const payload = { category_name: name, business_unit_id: buId };
    if (requestCategoryCode) {
        payload.request_category_code = requestCategoryCode;
    }

    fetch('{{ route("fin.expense-category.store") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success || (data.message && data.message.includes('successfully'))) {
            alert('Sub-category added!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed'));
        }
    })
    .catch(e => { console.error(e); alert('Failed to add.'); });
}

function deleteSubCategory(id, name) {
    if (!confirm('Remove sub-category "' + name + '"?')) return;

    fetch('/requests/settings/expense-subcategories/' + id, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('subCat-' + id);
            if (el) el.remove();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(e => { console.error(e); alert('Failed to delete.'); });
}
</script>

@endsection

