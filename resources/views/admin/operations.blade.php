@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-6">
    <h1 class="text-2xl font-semibold text-gray-900 mb-6">Operations</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Imports Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">Imports</h2>
            </div>
            <p class="text-sm text-gray-600 mb-4">Import historical orders from Shopify or WooCommerce.</p>
            <button onclick="openImportModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
                Open Import Dialog
            </button>
        </div>

        <!-- Bulk Delivery Status Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">Bulk Delivery Status</h2>
            </div>
            <p class="text-sm text-gray-600 mb-4">Upload a CSV to bulk-update order statuses (e.g., delivered).</p>
            <a href="/admin/bulk-status-update" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v16c0 .552.448 1 1 1h14a1 1 0 001-1V8l-6-6H5a1 1 0 00-1 1z"/></svg>
                Open Bulk Upload Page
            </a>
        </div>

        <!-- Import Products Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">Import Products</h2>
            </div>
            <p class="text-sm text-gray-600 mb-4">Import products and variants from Shopify or WooCommerce.</p>
            <button onclick="openImportProductsModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
                Open Product Import
            </button>
        </div>

        <!-- Rider Assignments Import Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">Import Rider Assignments</h2>
            </div>
            
            <!-- Enhanced Instructions -->
            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-md">
                <h3 class="text-sm font-semibold text-blue-800 mb-2">📋 CSV Format</h3>
                <div class="text-xs text-blue-700 space-y-1">
                    <p><strong>Required columns (any name format):</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li><code>Order Number</code> / <code>order_number</code> - Non-Shopify order number</li>
                        <li><code>Delivery_Rider</code> / <code>rider_name</code> / <code>Delivery Rider</code> - Rider's full name</li>
                    </ul>
                    <p class="mt-2"><strong>Optional:</strong> <code>Date</code> / <code>assigned_at</code></p>
                    
                    <div class="mt-3 p-2 bg-white rounded border border-blue-300">
                        <p class="font-medium mb-1">Example CSV:</p>
                        <pre class="text-xs">Order Number,Delivery_Rider,Date
9145,Arsalan,3/3/2025
9144,Jazib,3/3/2025
9141,Asim Tahir - Indri,3/3/2025</pre>
                    </div>
                    
                    <p class="mt-2"><strong>✓ Smart features:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Automatically cleans rider names (removes "- indrive", "- Indri")</li>
                        <li>Case-insensitive matching</li>
                        <li>Partial name matching (e.g., "Arsalan" finds "Arsalan Khan")</li>
                        <li>Only non-Shopify orders</li>
                    </ul>
                </div>
            </div>
            
            <form id="riderImportForm" action="{{ route('operations.rider-import') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="csv_file" accept=".csv,.txt" class="block w-full text-sm text-gray-500 mb-3 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    📤 Upload & Assign Riders
                </button>
            </form>
            @if(session('rider_import_result'))
                <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded">
                    {!! session('rider_import_result') !!}
                </div>
            @endif
        </div>

        <!-- Attendance Import Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">Import Attendance</h2>
            </div>
            <p class="text-sm text-gray-600 mb-4">Upload CSV with columns similar to your legacy sheet (date, employee, login time/location, logout time/location, device, meter, pictures).</p>
            <form id="attendanceImportForm" action="{{ route('operations.attendance-import') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="csv_file" accept=".csv,.txt" class="block w-full text-sm text-gray-500 mb-3">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Upload CSV
                </button>
            </form>
            @if(session('attendance_import_result'))
                <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded">
                    {!! session('attendance_import_result') !!}
                </div>
            @endif
        </div>

        <!-- Legacy Expense Sheet Import Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">Import Legacy Expense Sheet</h2>
            </div>
            
            <!-- Enhanced Instructions -->
            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                <h3 class="text-sm font-semibold text-green-800 mb-2">📊 Import Historical Data</h3>
                <div class="text-xs text-green-700 space-y-1">
                    <p><strong>This will import:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Employee invoices & cash balances</li>
                        <li>Vendor purchases & payables</li>
                        <li>Expense transactions</li>
                        <li>Employee deposits</li>
                    </ul>
                    
                    <p class="mt-2"><strong>⚠️ Important:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Employees must exist in user table</li>
                        <li>Unmatched employees will be listed</li>
                        <li>Safe to re-run (duplicates skipped)</li>
                    </ul>
                </div>
            </div>
            
            <form id="legacyImportForm" action="{{ route('fin.import.legacy') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="csv_file" accept=".csv,.txt" class="block w-full text-sm text-gray-500 mb-3 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    📥 Import Legacy Data
                </button>
                <a href="{{ route('fin.vendors.index') }}" class="ml-2 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View Vendors
                </a>
                <a href="{{ route('fin.employee.index') }}" class="ml-2 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View Employees
                </a>
            </form>
            @if(session('import_result'))
                <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded">
                    {!! session('import_result') !!}
                </div>
            @endif
        </div>

        <!-- Clear Legacy Data Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">🗑️ Clear Legacy Data (Testing)</h2>
            </div>
            
            <!-- Warning -->
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                <h3 class="text-sm font-semibold text-red-800 mb-2">⚠️ DANGER ZONE</h3>
                <div class="text-xs text-red-700 space-y-1">
                    <p><strong>This will permanently delete:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>All imported ledger transactions</li>
                        <li>All import history logs</li>
                        <li>Reset all account balances to 0</li>
                    </ul>
                    
                    <p class="mt-2"><strong>This will NOT delete:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Accounts (structure preserved)</li>
                        <li>Expense categories</li>
                        <li>Vendors</li>
                        <li>Employees</li>
                    </ul>
                    
                    <p class="mt-2 font-semibold">💡 Use this to clean up and re-import for testing!</p>
                </div>
            </div>
            
            <form id="clearLegacyForm" action="{{ route('fin.import.clear-legacy') }}" method="POST" onsubmit="return confirmClear()">
                @csrf
                <input type="hidden" name="confirmation" id="confirmationInput" value="">
                
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Type <code class="bg-red-100 text-red-800 px-2 py-1 rounded">DELETE_ALL_LEGACY_DATA</code> to confirm:
                    </label>
                    <input type="text" id="confirmationText" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-red-500" placeholder="DELETE_ALL_LEGACY_DATA">
                </div>
                
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    🗑️ Clear All Legacy Data
                </button>
                
                <p class="mt-3 text-xs text-gray-600">
                    <strong>Tip:</strong> After clearing, you can import your CSV again from scratch with corrected data or flows.
                </p>
            </form>
            
            @if(session('success'))
                <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded text-green-800 text-sm">
                    {!! session('success') !!}
                </div>
            @endif
            
            @if(session('error'))
                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded text-red-800 text-sm">
                    {!! session('error') !!}
                </div>
            @endif
        </div>

        <script>
        function confirmClear() {
            const confirmText = document.getElementById('confirmationText').value;
            
            if (confirmText !== 'DELETE_ALL_LEGACY_DATA') {
                alert('❌ Confirmation text does not match. Please type exactly: DELETE_ALL_LEGACY_DATA');
                return false;
            }
            
            const finalConfirm = confirm(
                '🚨 FINAL CONFIRMATION\n\n' +
                'This will DELETE ALL legacy imported data and reset balances to 0.\n\n' +
                'Are you absolutely sure you want to proceed?'
            );
            
            if (finalConfirm) {
                document.getElementById('confirmationInput').value = confirmText;
                return true;
            }
            
            return false;
        }
        </script>
    </div>
</div>

<!-- Include the same Import modal markup used on Orders page -->
@include('pages.orders.partials.import-modal')
@include('pages.products.partials.import-products-modal')

@endsection

