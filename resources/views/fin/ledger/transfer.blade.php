@extends('layouts.app')

@section('title', 'Account Transfer')

@section('content')
<div class="container mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">New Account Transfer</h1>
        <a href="{{ route('fin.ledger.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
            ← Back to Ledger
        </a>
    </div>

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <ul class="list-disc list-inside text-sm text-red-800">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Transfer Form -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <form action="{{ route('fin.ledger.transfer.store') }}" method="POST">
            @csrf
            
            <div class="space-y-6">
                <!-- Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Date *</label>
                    <input type="date" name="transaction_date" value="{{ old('transaction_date', date('Y-m-d')) }}" required
                           class="w-full md:w-1/2 px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- From Account -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">From Account *</label>
                    <select name="from_account_id" id="from_account_id" required onchange="renderTransferBankPicker()"
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Source Account --</option>
                        @foreach($accounts as $type => $typeAccounts)
                            <optgroup label="{{ ucfirst(str_replace('_', ' ', $type)) }}">
                                @foreach($typeAccounts as $account)
                                    <option value="{{ $account->id }}" data-account-category="{{ $account->account_category }}" {{ old('from_account_id') == $account->id ? 'selected' : '' }}>
                                        {{ $account->account_name }}
                                        (Balance: Rs. {{ number_format($account->current_balance, 2) }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Money will be deducted from this account</p>
                </div>

                <!-- To Account -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">To Account *</label>
                    <select name="to_account_id" id="to_account_id" required onchange="renderTransferBankPicker()"
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Destination Account --</option>
                        @foreach($accounts as $type => $typeAccounts)
                            <optgroup label="{{ ucfirst(str_replace('_', ' ', $type)) }}">
                                @foreach($typeAccounts as $account)
                                    <option value="{{ $account->id }}" data-account-category="{{ $account->account_category }}" {{ old('to_account_id') == $account->id ? 'selected' : '' }}>
                                        {{ $account->account_name }}
                                        (Balance: Rs. {{ number_format($account->current_balance, 2) }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Money will be added to this account</p>
                </div>

                <!-- ⭐ Receiving bank — mandatory when the transfer touches an ONLINE bank account -->
                <div id="transferBankField" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">🏦 Which bank? <span class="text-red-500">*</span></label>
                    <div id="transferBankChips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                    <input type="hidden" name="receiving_account_id" id="transfer_receiving_account_id" value="{{ old('receiving_account_id') }}">
                    <p class="mt-1 text-xs text-gray-500">The physical bank this transfer goes through — keeps per-bank balances correct.</p>
                </div>

                <!-- Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Amount (Rs.) *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" value="{{ old('amount') }}" required
                           class="w-full md:w-1/2 px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="mt-1 text-xs text-gray-500">Enter the transfer amount</p>
                </div>

                <!-- Mode -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Transfer Mode *</label>
                    <div class="space-y-2">
                        <label class="flex items-center">
                            <input type="radio" name="mode" value="cash" {{ old('mode', 'cash') === 'cash' ? 'checked' : '' }} required
                                   class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                            <span class="ml-2 text-sm text-gray-700">
                                Cash (Immediate transfer)
                            </span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="mode" value="online" {{ old('mode') === 'online' ? 'checked' : '' }} required
                                   class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                            <span class="ml-2 text-sm text-gray-700">
                                Online/Bank (Requires approval)
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description *</label>
                    <textarea name="description" rows="4" required
                              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('description') }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Provide details about this transfer (e.g., "Transfer from NF Cash to rider for advance")</p>
                </div>

                <!-- Submit Buttons -->
                <div class="flex gap-4 pt-4 border-t border-gray-200">
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                        🔄 Process Transfer
                    </button>
                    <a href="{{ route('fin.ledger.index') }}" class="px-6 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Help Section -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="text-sm font-semibold text-blue-900 mb-2">ℹ️ Transfer Guidelines</h3>
        <ul class="text-xs text-blue-800 space-y-1 list-disc list-inside">
            <li><strong>Cash transfers</strong> are processed immediately</li>
            <li><strong>Online transfers</strong> require approval before balances update</li>
            <li>For <strong>Asset accounts</strong> (NF Cash, Employee Cash), sufficient balance is required</li>
            <li>Common transfers: NF Cash → Online Bank, Employee Cash → NF Cash, etc.</li>
            <li>Use descriptive notes for audit trail</li>
        </ul>
    </div>
</div>

<script>
// ⭐ Receiving-bank picker — shown (and required) when either side of the
// transfer is an ONLINE bank-category account. Chips show computed balances.
const transferReceivingBanks = @json($receivingBanks ?? []);

function transferTouchesBank() {
    const fromSel = document.getElementById('from_account_id');
    const toSel = document.getElementById('to_account_id');
    const cat = sel => {
        const opt = sel && sel.options[sel.selectedIndex];
        return (opt && opt.dataset && opt.dataset.accountCategory) || '';
    };
    return cat(fromSel) === 'bank' || cat(toSel) === 'bank';
}

function selectTransferBank(id) {
    document.getElementById('transfer_receiving_account_id').value = id;
    renderTransferBankPicker();
}

function renderTransferBankPicker() {
    const field = document.getElementById('transferBankField');
    const chips = document.getElementById('transferBankChips');
    const hidden = document.getElementById('transfer_receiving_account_id');
    if (!field || !chips || !hidden) return;

    if (!transferTouchesBank() || transferReceivingBanks.length === 0) {
        field.style.display = 'none';
        hidden.value = '';
        return;
    }
    field.style.display = 'block';
    const current = hidden.value;
    chips.innerHTML = transferReceivingBanks.map(b => {
        const active = String(current) === String(b.id);
        const color = b.color_hex || '#3B82F6';
        const bal = (b.balance !== undefined && b.balance !== null)
            ? ` · Rs ${Math.round(Number(b.balance)).toLocaleString()}`
            : '';
        return `<button type="button" onclick="selectTransferBank(${b.id})" style="padding:6px 14px; border-radius:16px; border:1px solid ${active ? color : '#CBD5E1'}; background:${active ? color : '#F1F5F9'}; color:${active ? '#fff' : '#475569'}; font-size:13px; font-weight:600; cursor:pointer;">${(b.short_code || b.name)}<span style="font-weight:500; opacity:0.85;">${bal}</span></button>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', function() {
    renderTransferBankPicker(); // handle old() values after a validation error

    const form = document.querySelector('form[action*="transfer"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (transferTouchesBank() && !document.getElementById('transfer_receiving_account_id').value) {
                e.preventDefault();
                alert('Select which bank this transfer goes through.');
            }
        });
    }
});
</script>
@endsection

