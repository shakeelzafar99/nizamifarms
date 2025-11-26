{{-- 
    Reusable KPI Summary Cards Component
    
    Props:
    - $kpis: Array of KPI data from controller
    - $clickable: Boolean (default: false) - whether cards are clickable for filtering
--}}

@props(['kpis', 'clickable' => false])

<div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
    <!-- Card 1: Invoices Delivered -->
    <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 {{ $clickable ? 'cursor-pointer hover:shadow-md hover:border-blue-300 transition-all' : '' }}"
         @if($clickable) onclick="filterByCard('invoices')" @endif>
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xl">📄</span>
            <span class="text-xs font-semibold text-gray-600 uppercase">Invoices</span>
        </div>
        <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($kpis['total_invoices'], 0) }}</div>
        <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
            <div class="font-medium text-gray-700 mb-1">💵 Cash:</div>
            <div class="flex justify-between pl-2">
                <span>Deposits:</span>
                <span class="font-medium">Rs. {{ number_format($kpis['cash_deposits'], 0) }}</span>
            </div>
            <div class="flex justify-between pl-2 mt-1">
                <span>Short Cash:</span>
                <span class="font-medium text-orange-600">Rs. {{ number_format($kpis['short_cash_total'], 0) }}</span>
            </div>
            <div class="font-medium text-gray-700 mb-1 mt-2">💳 Online:</div>
            <div class="flex justify-between pl-2">
                <span>✓ Approved:</span>
                <span class="font-medium text-green-600">Rs. {{ number_format($kpis['online_approved'], 0) }}</span>
            </div>
            <div class="flex justify-between pl-2 mt-0.5">
                <span>⏳ Pending:</span>
                <span class="font-medium text-yellow-600">Rs. {{ number_format($kpis['online_pending'], 0) }}</span>
            </div>
            @if(isset($kpis['online_pending_l1']) && isset($kpis['online_pending_l2']))
            <div class="flex justify-between pl-2 mt-0.5 text-[10px]">
                <span class="pl-2">• Pending L1:</span>
                <span class="font-medium text-yellow-700">Rs. {{ number_format($kpis['online_pending_l1'], 0) }}</span>
            </div>
            <div class="flex justify-between pl-2 mt-0.5 text-[10px]">
                <span class="pl-2">• Pending L2:</span>
                <span class="font-medium text-orange-600">Rs. {{ number_format($kpis['online_pending_l2'], 0) }}</span>
            </div>
            @endif
        </div>
    </div>

    <!-- Card 2: All Expenses -->
    <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 {{ $clickable ? 'cursor-pointer hover:shadow-md hover:border-blue-300 transition-all' : '' }}"
         @if($clickable) onclick="filterByCard('expenses')" @endif>
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xl">🧾</span>
            <span class="text-xs font-semibold text-gray-600 uppercase">Expenses</span>
        </div>
        <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($kpis['total_expenses'], 0) }}</div>
        <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
            <div class="flex justify-between">
                <span>🧾 Regular:</span>
                <span class="font-medium">Rs. {{ number_format($kpis['regular_expenses'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1">
                <span>👤 Salaries:</span>
                <span class="font-medium">Rs. {{ number_format($kpis['salary_expenses'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1 pt-1 border-t border-gray-100">
                <span>⏳ Need Settlement:</span>
                <span class="font-medium text-yellow-600">Rs. {{ number_format($kpis['expenses_needing_settlement'], 0) }}</span>
            </div>
        </div>
    </div>

    <!-- Card 3: Vendor Balance -->
    <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 {{ $clickable ? 'cursor-pointer hover:shadow-md hover:border-blue-300 transition-all' : '' }}"
         @if($clickable) onclick="filterByCard('vendor')" @endif>
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xl">🏪</span>
            <span class="text-xs font-semibold text-gray-600 uppercase">Vendor</span>
        </div>
        <div class="text-xl font-bold {{ $kpis['vendor_balance'] > 0 ? 'text-red-600' : 'text-green-600' }}">
            Rs. {{ number_format($kpis['vendor_balance'], 0) }}
        </div>
        <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
            <div class="flex justify-between">
                <span>📦 Purchases:</span>
                <span class="font-medium">Rs. {{ number_format($kpis['vendor_purchases'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1">
                <span>💸 Payments:</span>
                <span class="font-medium">Rs. {{ number_format($kpis['vendor_payments'], 0) }}</span>
            </div>
        </div>
    </div>

    <!-- Card 4: Approvals & Riders Balance -->
    <a href="{{ route('fin.employee.all-outstanding-invoices') }}" 
       class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 hover:shadow-md hover:border-purple-300 transition-all cursor-pointer">
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xl">👥</span>
            <span class="text-xs font-semibold text-gray-600 uppercase">Riders</span>
        </div>
        <div class="text-xl font-bold {{ $kpis['riders_balance'] < 0 ? 'text-red-600' : 'text-green-600' }}">
            Rs. {{ number_format($kpis['riders_balance'], 0) }}
        </div>
        <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
            <div class="flex justify-between">
                <span>💰 Real-time:</span>
                <span class="font-medium">Rs. {{ number_format($kpis['riders_balance'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1 pt-1 border-t border-gray-100">
                <span>⏳ Pending In:</span>
                <span class="font-medium text-green-600">Rs. {{ number_format($kpis['pending_deposits'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1">
                <span>⏳ Pending Out:</span>
                <span class="font-medium text-red-600">Rs. {{ number_format($kpis['pending_expenses'], 0) }}</span>
            </div>
        </div>
    </a>

    <!-- Card 5: NF Profit -->
    <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200 {{ $clickable ? 'cursor-pointer hover:shadow-md hover:border-blue-300 transition-all' : '' }}"
         @if($clickable) onclick="filterByCard('profit')" @endif>
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xl">💰</span>
            <span class="text-xs font-semibold text-gray-600 uppercase">NF Profit</span>
        </div>
        <div class="text-xl font-bold {{ $kpis['profit'] < 0 ? 'text-red-600' : 'text-green-600' }}">
            Rs. {{ number_format($kpis['profit'], 0) }}
        </div>
        <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
            <div class="flex justify-between">
                <span>📄 Revenue:</span>
                <span class="font-medium text-green-600">Rs. {{ number_format($kpis['profit_invoices'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1">
                <span>🧾 Expenses:</span>
                <span class="font-medium text-red-600">Rs. {{ number_format($kpis['profit_expenses'], 0) }}</span>
            </div>
            <div class="flex justify-between mt-1">
                <span>🏪 Vendor:</span>
                <span class="font-medium text-red-600">Rs. {{ number_format($kpis['profit_vendor_purchases'], 0) }}</span>
            </div>
        </div>
    </div>
</div>

