@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Employee Cash</h1>
        <button onclick="window.history.back()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back
        </button>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-lg shadow-sm p-3 mb-4 border border-gray-200">
        <form method="GET" action="{{ route('fin.employee.index') }}" class="flex flex-wrap gap-2 items-end">
            <!-- Filter Type -->
            <div class="flex-shrink-0">
                <label class="block text-xs font-medium text-gray-700 mb-1">Period</label>
                <select name="filter_type" id="filterType" onchange="toggleFilterInputs()" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="month" {{ $summaryKPIs['filter_type'] === 'month' ? 'selected' : '' }}>Month</option>
                    <option value="day" {{ $summaryKPIs['filter_type'] === 'day' ? 'selected' : '' }}>Day</option>
                    <option value="custom" {{ $summaryKPIs['filter_type'] === 'custom' ? 'selected' : '' }}>Custom</option>
                </select>
            </div>

            <!-- Month Input -->
            <div class="flex-shrink-0" id="monthInput" style="display: {{ $summaryKPIs['filter_type'] === 'month' ? 'block' : 'none' }}">
                <label class="block text-xs font-medium text-gray-700 mb-1">Month</label>
                <input type="month" name="filter_month" value="{{ $summaryKPIs['filter_month'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <!-- Day Input -->
            <div class="flex-shrink-0" id="dayInput" style="display: {{ $summaryKPIs['filter_type'] === 'day' ? 'block' : 'none' }}">
                <label class="block text-xs font-medium text-gray-700 mb-1">Date</label>
                <input type="date" name="filter_date" value="{{ $summaryKPIs['filter_date'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <!-- Custom Range -->
            <div class="flex gap-2" id="customInput" style="display: {{ $summaryKPIs['filter_type'] === 'custom' ? 'flex' : 'none' }}">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">From</label>
                    <input type="date" name="filter_start_date" value="{{ $summaryKPIs['filter_start_date'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">To</label>
                    <input type="date" name="filter_end_date" value="{{ $summaryKPIs['filter_end_date'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 self-end">
                Apply
            </button>
            <button type="button" onclick="openAuditModal()" class="px-4 py-2 text-white text-sm font-semibold rounded-md shadow-md self-end" style="background: linear-gradient(to right, #7c3aed, #4f46e5) !important;">
                <span style="color: white !important;">🔍 Audit</span>
            </button>
        </form>
    </div>

    <!-- Audit Modal -->
    <div id="auditModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
        <div class="bg-white rounded-lg shadow-2xl max-w-6xl w-full flex flex-col" style="max-height: 90vh;">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-4 border-b border-purple-700 flex-shrink-0 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <h2 class="text-xl font-bold text-white">Ledger Audit Report</h2>
                    </div>
                    <button onclick="closeAuditModal()" class="text-white hover:text-gray-200 text-2xl font-bold leading-none">
                        ×
                    </button>
                </div>
            </div>

            <!-- Date Filter Section -->
            <div class="bg-gray-50 px-6 py-3 border-b border-gray-200 flex-shrink-0">
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input type="checkbox" id="auditIncludeLegacy" onchange="toggleAuditDateFilter()" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span>Include records before Nov 1, 2025</span>
                    </label>
                    <div id="auditCustomDateRange" class="hidden flex items-center gap-2">
                        <span class="text-sm text-gray-600">From:</span>
                        <input type="date" id="auditStartDate" value="2025-11-01" class="px-2 py-1 text-sm border border-gray-300 rounded">
                        <span class="text-sm text-gray-600">To:</span>
                        <input type="date" id="auditEndDate" value="{{ date('Y-m-d') }}" class="px-2 py-1 text-sm border border-gray-300 rounded">
                    </div>
                    <button onclick="refreshAuditReport()" class="ml-auto px-3 py-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-semibold rounded">
                        🔄 Refresh
                    </button>
                </div>
            </div>

            <!-- Modal Content (Scrollable) -->
            <div class="p-6 overflow-y-auto flex-1">
                <!-- Loading State -->
                <div id="auditLoading" class="text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div>
                    <p class="mt-4 text-gray-600">Running audit checks...</p>
                </div>

                <!-- Summary Section -->
                <div id="auditSummary" class="hidden mb-6 grid grid-cols-3 gap-4">
                    <div class="bg-gradient-to-br from-red-50 to-red-100 border-2 border-red-300 rounded-lg p-4 text-center">
                        <div class="text-3xl font-bold text-red-700" id="totalIssues">0</div>
                        <div class="text-sm font-medium text-red-600 mt-1">Total Issues</div>
                    </div>
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 border-2 border-orange-300 rounded-lg p-4 text-center">
                        <div class="text-3xl font-bold text-orange-700" id="criticalIssues">0</div>
                        <div class="text-sm font-medium text-orange-600 mt-1">Critical Issues</div>
                    </div>
                    <div class="bg-gradient-to-br from-green-50 to-green-100 border-2 border-green-300 rounded-lg p-4 text-center">
                        <div class="text-3xl font-bold text-green-700" id="issueTypes">0</div>
                        <div class="text-sm font-medium text-green-600 mt-1">Issue Types</div>
                    </div>
                </div>

                <!-- Issues List -->
                <div id="auditIssues" class="hidden space-y-4">
                    <!-- Will be populated by JavaScript -->
                </div>

                <!-- No Issues State -->
                <div id="auditNoIssues" class="hidden text-center py-12">
                    <div class="text-6xl mb-4">✅</div>
                    <h3 class="text-2xl font-bold text-green-700 mb-2">All Clear!</h3>
                    <p class="text-gray-600">No ledger integrity issues detected.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary KPI Cards (Enhanced - 5 Cards) -->
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
        <!-- Card 1: Invoices Delivered -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">📄</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Invoices</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['total_invoices'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="font-medium text-gray-700 mb-1">💵 Cash:</div>
                <div class="flex justify-between pl-2">
                    <span>Deposits:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['cash_deposits'], 0) }}</span>
                </div>
                <div class="flex justify-between pl-2 mt-1">
                    <span>Short Cash:</span>
                    <span class="font-medium text-orange-600">Rs. {{ number_format($summaryKPIs['short_cash_total'], 0) }}</span>
                </div>
                <div class="font-medium text-gray-700 mb-1 mt-2">💳 Online:</div>
                <div class="flex justify-between pl-2">
                    <span>✓ Approved:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['online_approved'], 0) }}</span>
                </div>
                <div class="flex justify-between pl-2 mt-1">
                    <span>⏳ Pending:</span>
                    <span class="font-medium text-yellow-600">Rs. {{ number_format($summaryKPIs['online_pending'], 0) }}</span>
                </div>
            </div>
        </div>

        <!-- Card 2: All Expenses -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">🧾</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Expenses</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['total_expenses'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="flex justify-between">
                    <span>🧾 Regular:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['regular_expenses'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>👤 Salaries:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['salary_expenses'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1 pt-1 border-t border-gray-100">
                    <span>⏳ Need Settlement:</span>
                    <span class="font-medium text-yellow-600">Rs. {{ number_format($summaryKPIs['expenses_needing_settlement'], 0) }}</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Vendor Balance -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">🏪</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Vendor</span>
            </div>
            <div class="text-xl font-bold {{ $summaryKPIs['vendor_balance'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                Rs. {{ number_format($summaryKPIs['vendor_balance'], 0) }}
            </div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="flex justify-between">
                    <span>📦 Purchases:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['vendor_purchases'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>💸 Payments:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['vendor_payments'], 0) }}</span>
                </div>
            </div>
        </div>

        <!-- Card 4: Approvals & Riders Balance -->
        <a href="{{ route('fin.employee.all-outstanding-invoices') }}" class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 hover:shadow-md hover:border-purple-300 transition-all cursor-pointer">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">👥</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Riders</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['riders_balance'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-blue-600 font-medium">⚡ Real-time</span>
                </div>
                <div class="flex justify-between text-gray-500">
                    <span>⬇️ Pending In:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['pending_deposits'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1 text-gray-500">
                    <span>⬆️ Pending Out:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['pending_expenses'], 0) }}</span>
                </div>
            </div>
        </a>

        <!-- Card 5: NF Balance (Profit) -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 {{ $summaryKPIs['profit'] >= 0 ? 'border-green-300' : 'border-red-300' }}">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">💰</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">NF Balance</span>
            </div>
            <div class="text-xl font-bold {{ $summaryKPIs['profit'] >= 0 ? 'text-green-600' : 'text-red-600' }}">Rs. {{ number_format($summaryKPIs['profit'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="flex justify-between">
                    <span>📊 Revenue:</span>
                    <span class="font-medium text-green-600">Rs. {{ number_format($summaryKPIs['profit_invoices'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>🧾 Expenses:</span>
                    <span class="font-medium text-red-600">Rs. {{ number_format($summaryKPIs['profit_expenses'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>🏪 Vendor:</span>
                    <span class="font-medium text-red-600">Rs. {{ number_format($summaryKPIs['profit_vendor_purchases'], 0) }}</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleFilterInputs() {
            const filterType = document.getElementById('filterType').value;
            document.getElementById('monthInput').style.display = filterType === 'month' ? 'block' : 'none';
            document.getElementById('dayInput').style.display = filterType === 'day' ? 'block' : 'none';
            document.getElementById('customInput').style.display = filterType === 'custom' ? 'flex' : 'none';
        }

        // ================================================================
        // AUDIT MODAL FUNCTIONS (same as NF Ledger)
        // ================================================================
        let auditData = null;

        async function openAuditModal() {
            const modal = document.getElementById('auditModal');
            modal.classList.remove('hidden');
            
            // Prevent background scrolling
            document.body.style.overflow = 'hidden';
            
            await refreshAuditReport();
        }

        function closeAuditModal() {
            document.getElementById('auditModal').classList.add('hidden');
            
            // Restore background scrolling
            document.body.style.overflow = 'auto';
        }

        function toggleAuditDateFilter() {
            const checkbox = document.getElementById('auditIncludeLegacy');
            const customRange = document.getElementById('auditCustomDateRange');
            
            if (checkbox.checked) {
                customRange.classList.remove('hidden');
            } else {
                customRange.classList.add('hidden');
            }
        }

        async function refreshAuditReport() {
            document.getElementById('auditLoading').classList.remove('hidden');
            document.getElementById('auditSummary').classList.add('hidden');
            document.getElementById('auditIssues').classList.add('hidden');
            document.getElementById('auditNoIssues').classList.add('hidden');
            
            // Build query params
            let url = '/finance/ledger/audit/report';
            const includeLegacy = document.getElementById('auditIncludeLegacy').checked;
            
            if (includeLegacy) {
                const startDate = document.getElementById('auditStartDate').value;
                const endDate = document.getElementById('auditEndDate').value;
                url += `?start_date=${startDate}&end_date=${endDate}`;
            } else {
                // Default: only from Nov 1, 2025 onwards
                url += '?start_date=2025-11-01';
            }
            
            try {
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    auditData = data;
                    displayAuditReport(data);
                } else {
                    alert('Failed to load audit report: ' + (data.message || 'Unknown error'));
                    closeAuditModal();
                }
            } catch (error) {
                console.error('Audit report error:', error);
                alert('Failed to load audit report. Check console for details.');
                closeAuditModal();
            }
        }

        function displayAuditReport(data) {
            document.getElementById('auditLoading').classList.add('hidden');
            
            if (data.issues.length === 0) {
                document.getElementById('auditNoIssues').classList.remove('hidden');
                return;
            }
            
            document.getElementById('totalIssues').textContent = data.summary.total_issues;
            document.getElementById('criticalIssues').textContent = data.summary.critical_issues;
            document.getElementById('issueTypes').textContent = data.summary.issue_types;
            document.getElementById('auditSummary').classList.remove('hidden');
            
            const issuesContainer = document.getElementById('auditIssues');
            issuesContainer.innerHTML = '';
            
            data.issues.forEach((issue, index) => {
                const issueCard = createIssueCard(issue, index);
                issuesContainer.appendChild(issueCard);
            });
            
            document.getElementById('auditIssues').classList.remove('hidden');
        }

        function createIssueCard(issue, index) {
            const card = document.createElement('div');
            const severityColors = {
                high: 'border-red-300 bg-red-50',
                medium: 'border-orange-300 bg-orange-50',
                low: 'border-yellow-300 bg-yellow-50'
            };
            const severityBadgeColors = {
                high: 'bg-red-200 text-red-800',
                medium: 'bg-orange-200 text-orange-800',
                low: 'bg-yellow-200 text-yellow-800'
            };
            
            card.className = `border-2 ${severityColors[issue.severity]} rounded-lg p-4`;
            
            let itemsHtml = '';
            if (issue.type === 'missing_invoice_ledger') {
                itemsHtml = `
                    <div class="bg-white rounded-lg overflow-hidden border border-red-200 mt-3">
                        <table class="min-w-full divide-y divide-red-200 text-sm">
                            <thead class="bg-red-100">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">
                                        <input type="checkbox" id="selectAll_${index}" onchange="toggleSelectAll(${index})" class="rounded">
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Order #</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Date</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Customer</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Rider</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-red-900">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-red-100" id="issueItems_${index}">
                                ${issue.items.map(item => `
                                    <tr class="hover:bg-red-50">
                                        <td class="px-3 py-2">
                                            <input type="checkbox" name="issue_${index}_items" value="${item.order_id}" class="rounded issue-checkbox-${index}">
                                        </td>
                                        <td class="px-3 py-2 font-medium text-gray-900">${item.order_number}</td>
                                        <td class="px-3 py-2 text-gray-600">${item.order_date}</td>
                                        <td class="px-3 py-2 text-gray-600">${item.customer_name}</td>
                                        <td class="px-3 py-2 text-gray-600">${item.rider_name}</td>
                                        <td class="px-3 py-2 text-right font-medium text-gray-900">Rs. ${parseFloat(item.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button onclick="fixMissingInvoices(${index})" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                            ✓ Fix Selected
                        </button>
                        <button onclick="fixMissingInvoices(${index}, true)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                            ✓ Fix All
                        </button>
                    </div>
        `;
    } else if (issue.type === 'missing_expense_ledger') {
        itemsHtml = `
            <div class="bg-white rounded-lg overflow-hidden border border-red-200 mt-3">
                <table class="min-w-full divide-y divide-red-200 text-sm">
                    <thead class="bg-red-100">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">
                                <input type="checkbox" id="selectAll_${index}" onchange="toggleSelectAll(${index})" class="rounded">
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Request #</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Requester</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Category</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Approved Date</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-red-900">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100" id="issueItems_${index}">
                        ${issue.items.map(item => `
                            <tr class="hover:bg-red-50">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="issue_${index}_items" value="${item.request_id}" class="rounded issue-checkbox-${index}">
                                </td>
                                <td class="px-3 py-2 font-medium text-gray-900">${item.request_number}</td>
                                <td class="px-3 py-2 text-gray-600">${item.requester_name}</td>
                                <td class="px-3 py-2 text-gray-600 text-xs">${item.expense_category}</td>
                                <td class="px-3 py-2 text-gray-600">${item.approved_at}</td>
                                <td class="px-3 py-2 text-right font-medium text-gray-900">Rs. ${parseFloat(item.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex gap-2">
                <button onclick="fixMissingExpenses(${index})" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix Selected
                </button>
                <button onclick="fixMissingExpenses(${index}, true)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix All
                </button>
            </div>
        `;
    } else if (issue.type === 'incomplete_settlement') {
                itemsHtml = `
                    <div class="bg-white rounded-lg overflow-hidden border border-red-200 mt-3">
                        <table class="min-w-full divide-y divide-red-200 text-sm">
                            <thead class="bg-red-100">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">
                                        <input type="checkbox" id="selectAll_${index}" onchange="toggleSelectAll(${index})" class="rounded">
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Deposit Description</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Date</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-red-900">Amount</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-red-900">Unsettled Invoices</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-red-100" id="issueItems_${index}">
                                ${issue.items.map(item => `
                                    <tr class="hover:bg-red-50">
                                        <td class="px-3 py-2">
                                            <input type="checkbox" name="issue_${index}_items" value="${item.deposit_id}" class="rounded issue-checkbox-${index}">
                                        </td>
                                        <td class="px-3 py-2 font-medium text-gray-900 text-xs">${item.deposit_description}</td>
                                        <td class="px-3 py-2 text-gray-600">${item.deposit_date}</td>
                                        <td class="px-3 py-2 text-right font-medium text-gray-900">Rs. ${parseFloat(item.deposit_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                        <td class="px-3 py-2 text-center text-gray-900">${item.unsettled_count}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button onclick="fixIncompleteSettlements(${index})" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                            ✓ Fix Selected
                        </button>
                        <button onclick="fixIncompleteSettlements(${index}, true)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                            ✓ Fix All
                        </button>
                    </div>
                `;
            } else {
                itemsHtml = `<div class="mt-2 text-sm text-gray-600">${issue.count} item(s) affected</div>`;
            }
            
            card.innerHTML = `
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">⚠️</span>
                        <h3 class="text-lg font-bold text-gray-900">${issue.title}</h3>
                    </div>
                    <span class="px-2 py-1 ${severityBadgeColors[issue.severity]} text-xs font-bold rounded-full uppercase">${issue.severity}</span>
                </div>
                <p class="text-sm text-gray-700 mb-2">${issue.description}</p>
                ${itemsHtml}
            `;
            
            return card;
        }

        function toggleSelectAll(issueIndex) {
            const selectAll = document.getElementById(`selectAll_${issueIndex}`);
            const checkboxes = document.querySelectorAll(`.issue-checkbox-${issueIndex}`);
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }

        async function fixMissingInvoices(issueIndex, fixAll = false) {
            const issue = auditData.issues[issueIndex];
            let orderIds;
            
            if (fixAll) {
                orderIds = issue.items.map(item => item.order_id);
            } else {
                const checked = document.querySelectorAll(`input[name="issue_${issueIndex}_items"]:checked`);
                orderIds = Array.from(checked).map(cb => parseInt(cb.value));
            }
            
            if (orderIds.length === 0) {
                alert('Please select at least one order to fix.');
                return;
            }
            
            if (!confirm(`Fix ${orderIds.length} missing invoice(s)? This will create ledger entries for these orders.`)) {
                return;
            }
            
            try {
                const response = await fetch('/finance/ledger/audit/fix-missing-invoices', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ order_ids: orderIds })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    openAuditModal();
                } else {
                    alert('Failed to fix invoices: ' + data.message);
                }
            } catch (error) {
                console.error('Fix error:', error);
                alert('Failed to fix invoices. Check console for details.');
            }
        }

        async function fixMissingExpenses(issueIndex, fixAll = false) {
            const issue = auditData.issues[issueIndex];
            let requestIds;
            
            if (fixAll) {
                requestIds = issue.items.map(item => item.request_id);
            } else {
                const checked = document.querySelectorAll(`input[name="issue_${issueIndex}_items"]:checked`);
                requestIds = Array.from(checked).map(cb => parseInt(cb.value));
            }
            
            if (requestIds.length === 0) {
                alert('Please select at least one expense request to fix.');
                return;
            }
            
            if (!confirm(`Fix ${requestIds.length} missing expense ledger(s)? This will create ledger entries for these approved expense requests.`)) {
                return;
            }
            
            try {
                const response = await fetch('{{ route("fin.ledger.audit.fix-missing-expenses") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ request_ids: requestIds })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    // Refresh audit report
                    openAuditModal();
                } else {
                    alert('Failed to fix expenses: ' + data.message);
                }
            } catch (error) {
                console.error('Fix error:', error);
                alert('Failed to fix expenses. Check console for details.');
            }
        }

        async function fixIncompleteSettlements(issueIndex, fixAll = false) {
            const issue = auditData.issues[issueIndex];
            let depositIds;
            
            if (fixAll) {
                depositIds = issue.items.map(item => item.deposit_id);
            } else {
                const checked = document.querySelectorAll(`input[name="issue_${issueIndex}_items"]:checked`);
                depositIds = Array.from(checked).map(cb => parseInt(cb.value));
            }
            
            if (depositIds.length === 0) {
                alert('Please select at least one settlement to fix.');
                return;
            }
            
            if (!confirm(`Fix ${depositIds.length} incomplete settlement(s)? This will re-process invoice settlement for these deposits.`)) {
                return;
            }
            
            try {
                const response = await fetch('/finance/ledger/audit/fix-incomplete-settlements', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ deposit_ids: depositIds })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    openAuditModal();
                } else {
                    alert('Failed to fix settlements: ' + data.message);
                }
            } catch (error) {
                console.error('Fix error:', error);
                alert('Failed to fix settlements. Check console for details.');
            }
        }
    </script>

    <!-- Search and Filter -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <form method="GET" action="{{ route('fin.employee.index') }}" class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="{{ request('search') }}" 
                       placeholder="Search accounts..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <select name="account_type" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="all" {{ $accountTypeFilter == 'all' ? 'selected' : '' }}>All Accounts</option>
                <option value="employees" {{ $accountTypeFilter == 'employees' ? 'selected' : '' }}>👤 Employees Only</option>
                <option value="company" {{ $accountTypeFilter == 'company' ? 'selected' : '' }}>🏢 Company Only</option>
                <option value="NF_CASH" {{ $accountTypeFilter == 'NF_CASH' ? 'selected' : '' }}>💵 NF Cash</option>
                <option value="ONLINE" {{ $accountTypeFilter == 'ONLINE' ? 'selected' : '' }}>🏦 Online</option>
                <option value="EXP_FUND" {{ $accountTypeFilter == 'EXP_FUND' ? 'selected' : '' }}>💼 Expense Fund</option>
            </select>
            <select name="balance_filter" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">All Balances</option>
                <option value="positive" {{ request('balance_filter') == 'positive' ? 'selected' : '' }}>Positive Balance</option>
                <option value="zero" {{ request('balance_filter') == 'zero' ? 'selected' : '' }}>Zero Balance</option>
                <option value="negative" {{ request('balance_filter') == 'negative' ? 'selected' : '' }}>Negative Balance</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                Search
            </button>
        </form>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Accounts Table -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Code</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Balance</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Pending Actions</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @php
                    $lastCategory = null;
                @endphp
                @forelse($accounts as $account)
                    @php
                        $isCompanyAccount = in_array($account->account_category, ['cash', 'bank']);
                        $currentCategory = $isCompanyAccount ? 'company' : 'employee';
                        $showSeparator = $lastCategory !== null && $lastCategory !== $currentCategory;
                        $lastCategory = $currentCategory;
                    @endphp
                    
                    @if($showSeparator)
                        <tr class="bg-gradient-to-r from-gray-100 via-gray-50 to-gray-100">
                            <td colspan="7" class="px-6 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                                    <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                        👤 Employee Accounts
                                    </span>
                                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                                </div>
                            </td>
                        </tr>
                    @endif
                    
                    <tr class="hover:bg-gray-50 {{ $isCompanyAccount ? 'bg-green-50/30' : 'bg-blue-50/20' }}">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $account->account_name }}</div>
                            @if($account->user)
                                <div class="text-xs text-gray-500">{{ $account->user->username }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($account->account_category === 'employee_cash')
                                <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded">👤 Employee</span>
                            @elseif($account->account_category === 'cash')
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">🏢 Company Cash</span>
                            @elseif($account->account_category === 'bank')
                                <span class="px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded">🏦 Bank</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded">{{ ucfirst($account->account_category) }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-xs font-mono text-gray-600 bg-gray-100 px-2 py-1 rounded">{{ $account->account_code }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ $account->current_balance > 0 ? 'text-green-600' : ($account->current_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                Rs. {{ number_format($account->current_balance, 2) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ $account->pending_approvals > 0 ? 'text-yellow-600' : 'text-gray-400' }}">
                                Rs. {{ number_format($account->pending_approvals, 2) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ $account->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $account->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <a href="{{ route('fin.employee.show', $account->id) }}" class="text-blue-600 hover:text-blue-900">View Ledger</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                            No accounts found matching your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($accounts->hasPages())
        <div class="mt-4">
            {{ $accounts->appends(request()->query())->links() }}
        </div>
    @endif
</div>
@endsection

