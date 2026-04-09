@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-6" style="max-width: 1400px;">
    <h1 class="text-2xl font-semibold text-gray-900 mb-6">Operations</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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

        <!-- Import Historical Orders Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">📜 Import Historical Orders</h2>
            </div>
            
            <!-- Info Box -->
            <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-md">
                <h3 class="text-sm font-semibold text-amber-800 mb-2">📊 Load Legacy Order Data</h3>
                <div class="text-xs text-amber-700 space-y-1">
                    <p><strong>This will import from:</strong></p>
                    <p class="font-mono text-xs bg-amber-100 px-2 py-1 rounded">public/downloads/NF_Data_Center_Data_History.csv</p>
                    
                    <p class="mt-2"><strong>What happens:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Creates orders in <code>t_crm_history_order</code></li>
                        <li>Creates line items in <code>t_crm_history_order_line_item</code></li>
                        <li>Matches/creates customers by phone</li>
                        <li>Updates first/last order dates</li>
                    </ul>
                    
                    <p class="mt-2"><strong>✅ Safe to re-run:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Duplicates detected by order_number</li>
                        <li>Skips already imported orders</li>
                        <li>Can resume if interrupted</li>
                    </ul>
                    
                    <p class="mt-2"><strong>⚠️ Important:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Run migration SQL first!</li>
                        <li>May take 5-10 minutes for large files</li>
                    </ul>
                </div>
            </div>
            
            <!-- Progress Display -->
            <div id="historyImportProgress" class="hidden mb-4">
                <div class="flex justify-between text-sm text-gray-600 mb-1">
                    <span>Importing...</span>
                    <span id="historyImportStatus">Please wait</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div id="historyImportBar" class="bg-amber-600 h-2 rounded-full transition-all duration-300 animate-pulse" style="width: 100%"></div>
                </div>
            </div>
            
            <!-- Result Display -->
            <div id="historyImportResult" class="hidden mb-4 p-3 rounded-lg"></div>
            
            <!-- Action Button -->
            <button type="button" 
                    id="startHistoryImportBtn"
                    onclick="startHistoryImport()"
                    class="w-full inline-flex items-center justify-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500">
                📥 Start History Import
            </button>
        </div>

        <!-- Update History Delivery Dates Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">📅 Update History Delivery Dates</h2>
            </div>
            
            <!-- Info Box -->
            <div class="mb-4 p-4 bg-teal-50 border border-teal-200 rounded-md">
                <h3 class="text-sm font-semibold text-teal-800 mb-2">🚚 Fix Delivery Dates for History Orders</h3>
                <div class="text-xs text-teal-700 space-y-1">
                    <p><strong>CSV Format (same as production bulk update):</strong></p>
                    <div class="mt-2 p-2 bg-white rounded border border-teal-300">
                        <pre class="text-xs">Order Number,Delivery Status,Delivery Date
1899022021022021,delivered,2/1/2021 22:30:15
1901022021022021,delivered,2/2/2021 22:07:06</pre>
                    </div>
                    
                    <p class="mt-2"><strong>What happens:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Updates <code>delivered_at</code> in history orders</li>
                        <li>Only processes rows with status = "delivered"</li>
                        <li>Matches by Order Number</li>
                    </ul>
                </div>
            </div>
            
            <form action="{{ route('operations.history-delivery-update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="csv_file" accept=".csv,.txt" class="block w-full text-sm text-gray-500 mb-3 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
                <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                    📅 Update Delivery Dates
                </button>
            </form>
            @if(session('history_delivery_result'))
                <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded">
                    {!! session('history_delivery_result') !!}
                </div>
            @endif
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
                
                <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
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

        <!-- ⭐ Customer Address Geocoding Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">📍 Geocode Customer Addresses</h2>
            </div>
            
            <!-- Info -->
            <div class="mb-4 p-4 bg-purple-50 border border-purple-200 rounded-md">
                <h3 class="text-sm font-semibold text-purple-800 mb-2">🗺️ Convert Addresses to Map Coordinates</h3>
                <div class="text-xs text-purple-700 space-y-1">
                    <p><strong>This will:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Find customers with orders in the last 30 days</li>
                        <li>Convert their addresses to GPS coordinates</li>
                        <li>Store separately from verified locations</li>
                        <li>Enable map display for orders without GPS</li>
                    </ul>
                    
                    <p class="mt-2"><strong>⚠️ Note:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Uses OpenStreetMap (free, 1 request/second)</li>
                        <li>Some vague addresses may fail</li>
                        <li>Safe to run multiple times</li>
                    </ul>
                </div>
            </div>
            
            <!-- Stats Display -->
            <div id="geocodingStats" class="p-4 bg-gray-50 rounded-lg mb-4">
                <div class="text-sm text-gray-600">Loading stats...</div>
            </div>
            
            <!-- Progress Display -->
            <div id="geocodingProgress" class="hidden mb-4">
                <div class="flex justify-between text-sm text-gray-600 mb-1">
                    <span>Progress</span>
                    <span id="geocodingProgressText">0 / 0</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div id="geocodingProgressBar" class="bg-purple-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <div id="geocodingLog" class="mt-3 text-xs text-gray-500 max-h-32 overflow-y-auto"></div>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex gap-2">
                <button type="button" 
                        id="startGeocodingBtn"
                        onclick="startGeocoding()"
                        class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                    📍 Start Geocoding
                </button>
                <button type="button" 
                        id="stopGeocodingBtn"
                        onclick="stopGeocoding()"
                        class="hidden flex-1 inline-flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md">
                    ⏹️ Stop
                </button>
            </div>
        </div>

        <!-- Ledger Settings Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">⚙️ Ledger Settings</h2>
            </div>
            
            <!-- Info -->
            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-md">
                <h3 class="text-sm font-semibold text-blue-800 mb-2">🔄 Automatic Ledger Posting</h3>
                <div class="text-xs text-blue-700 space-y-1">
                    <p><strong>When ENABLED:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>Orders marked as "delivered" automatically post to ledger</li>
                        <li>Employee cash accounts are updated in real-time</li>
                        <li>Online payments require L1/L2 approval</li>
                    </ul>
                    
                    <p class="mt-2"><strong>When DISABLED:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>No automatic ledger entries</li>
                        <li>Useful for testing or manual control</li>
                        <li>You can manually post later</li>
                    </ul>
                </div>
            </div>
            
            @php
                $autoPostEnabled = \App\Models\FIN\ConfigModel::where('config_key', 'LEDGER_AUTO_POST_ENABLED')->value('config_value');
                $isEnabled = $autoPostEnabled === '1';
            @endphp
            
            <!-- Current Status & Toggle -->
            <div class="p-4 bg-gray-50 rounded-lg mb-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Current Status</h3>
                        <p class="text-xs text-gray-600 mt-1">Automatic posting is currently:</p>
                    </div>
                    <span id="statusBadge" class="px-4 py-2 rounded-full text-sm font-bold {{ $isEnabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $isEnabled ? 'ENABLED' : 'DISABLED' }}
                    </span>
                </div>
                
                <div class="mt-4">
                    <button type="button" 
                            id="toggleButton" 
                            onclick="toggleLedgerPosting()"
                            class="w-full px-4 py-3 text-sm font-semibold rounded-lg transition-colors duration-200 {{ $isEnabled ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-green-600 hover:bg-green-700 text-white' }}">
                        {{ $isEnabled ? '⏸️ Disable Automatic Posting' : '▶️ Enable Automatic Posting' }}
                    </button>
                </div>
            </div>
            
            <!-- Link to Action Items -->
            <a href="{{ route('fin.action-items.index') }}" class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                <i class="ki-filled ki-information-2 mr-2"></i>
                View Ledger Action Items
            </a>
            
            <div id="toggleFeedback" class="mt-3 hidden"></div>
        </div>

        <!-- Manage Expense Categories Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">💸 Manage Expense Categories</h2>
            </div>
            
            <!-- Info -->
            <div class="mb-4 p-4 bg-purple-50 border border-purple-200 rounded-md">
                <h3 class="text-sm font-semibold text-purple-800 mb-2">📁 Expense Categories</h3>
                <div class="text-xs text-purple-700 space-y-1">
                    <p>Expense categories are used when requesting reimbursements. Each category automatically creates an expense account in the ledger.</p>
                    <p class="mt-2"><strong>Example:</strong></p>
                    <ul class="list-disc list-inside ml-2">
                        <li>"Petrol" → Creates account "EXP_PETROL"</li>
                        <li>"Office Supplies" → Creates account "EXP_OFFICE_SUPPLIES"</li>
                    </ul>
                </div>
            </div>
            
            @php
                $expenseCategories = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
                    ->orderBy('config_value')
                    ->get();
            @endphp
            
            <!-- Current Categories -->
            <div class="mb-4">
                <h3 class="text-sm font-semibold text-gray-700 mb-2">Current Categories ({{ $expenseCategories->count() }}):</h3>
                <div class="max-h-32 overflow-y-auto bg-gray-50 border border-gray-200 rounded p-2">
                    @if($expenseCategories->count() > 0)
                        <div class="flex flex-wrap gap-2">
                            @foreach($expenseCategories as $cat)
                                <span class="px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">{{ $cat->config_value }}</span>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-gray-500 italic">No categories yet. Add your first category below.</p>
                    @endif
                </div>
            </div>
            
            <!-- Add New Category -->
            <div class="border-t border-gray-200 pt-4">
                <button onclick="openAddExpenseCategoryModal()" class="w-full inline-flex items-center justify-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md">
                    ➕ Add New Expense Category
                </button>
            </div>
        </div>

        <!-- Manage Asset Categories Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">📦 Manage Asset Categories</h2>
            </div>
            
            <!-- Info -->
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-md">
                <h3 class="text-sm font-semibold text-emerald-800 mb-2">🏷️ Asset Categories</h3>
                <p class="text-sm text-emerald-700">Categorize company assets (equipment, vehicles, furniture, etc.) for better tracking and reporting.</p>
            </div>
            
            <!-- Existing Categories -->
            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Current Categories:</h3>
                <div class="flex flex-wrap gap-2" id="assetCategoriesList">
                    @php
                        $assetCategories = \App\Models\FIN\AssetCategoryModel::where('is_active', 1)->orderBy('sort_order')->get();
                    @endphp
                    @if($assetCategories->count() > 0)
                        @foreach($assetCategories as $cat)
                            <span class="inline-flex items-center px-3 py-1 text-sm font-medium rounded-full bg-emerald-100 text-emerald-800">
                                {{ $cat->name }}
                                @if($cat->useful_life_years)
                                    <span class="ml-1 text-xs text-emerald-600">({{ $cat->useful_life_years }}y)</span>
                                @endif
                            </span>
                        @endforeach
                    @else
                        <p class="text-xs text-gray-500 italic">No categories yet. Add your first category below.</p>
                    @endif
                </div>
            </div>
            
            <!-- Add New Asset Category -->
            <div class="border-t border-gray-200 pt-4">
                <button onclick="openAddAssetCategoryModal()" class="w-full inline-flex items-center justify-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md">
                    ➕ Add New Asset Category
                </button>
            </div>
        </div>

        <!-- Qurbani Mode Toggle Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-800">🐄 Qurbani Mode</h2>
            </div>
            
            <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-md">
                <h3 class="text-sm font-semibold text-amber-800 mb-2">Qurbani Section Visibility</h3>
                <div class="text-xs text-amber-700 space-y-1">
                    <p>Controls whether the Qurbani section appears in the web sidebar menu and mobile app.</p>
                    <p><strong>When enabled:</strong> Qurbani Orders and Settings links appear in sidebar.</p>
                    <p><strong>When disabled:</strong> Qurbani section is hidden from sidebar.</p>
                </div>
            </div>
            
            @php
                $qurbaniModeEnabled = \App\Models\FIN\ConfigModel::get('qurbani_mode_enabled', '1') === '1';
            @endphp
            
            <div class="p-4 bg-gray-50 rounded-lg mb-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Current Status</h3>
                        <p class="text-xs text-gray-600 mt-1">Qurbani mode is currently:</p>
                    </div>
                    <span id="qurbaniStatusBadge" class="px-4 py-2 rounded-full text-sm font-bold {{ $qurbaniModeEnabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $qurbaniModeEnabled ? 'ENABLED' : 'DISABLED' }}
                    </span>
                </div>
                
                <div class="mt-4">
                    <button type="button" 
                            id="qurbaniToggleButton" 
                            onclick="toggleQurbaniMode()"
                            class="w-full px-4 py-3 text-sm font-semibold rounded-lg transition-colors duration-200 {{ $qurbaniModeEnabled ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-green-600 hover:bg-green-700 text-white' }}">
                        {{ $qurbaniModeEnabled ? 'Disable Qurbani Mode' : 'Enable Qurbani Mode' }}
                    </button>
                </div>
            </div>
            
            <div id="qurbaniFeedback" class="mt-3 hidden"></div>
        </div>

        <script>
        function toggleQurbaniMode() {
            const btn = document.getElementById('qurbaniToggleButton');
            const badge = document.getElementById('qurbaniStatusBadge');
            const feedback = document.getElementById('qurbaniFeedback');
            
            const currentlyEnabled = btn.textContent.includes('Disable');
            btn.disabled = true;
            btn.textContent = 'Updating...';
            
            fetch('{{ route("qurbani.api.toggle") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const enabled = data.enabled;
                    badge.textContent = enabled ? 'ENABLED' : 'DISABLED';
                    badge.className = 'px-4 py-2 rounded-full text-sm font-bold ' + (enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800');
                    btn.textContent = enabled ? 'Disable Qurbani Mode' : 'Enable Qurbani Mode';
                    btn.className = 'w-full px-4 py-3 text-sm font-semibold rounded-lg transition-colors duration-200 ' + (enabled ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-green-600 hover:bg-green-700 text-white');
                    feedback.innerHTML = '<div class="p-2 bg-green-50 border border-green-200 rounded text-green-800 text-xs">' + data.message + '</div>';
                    feedback.classList.remove('hidden');
                    setTimeout(() => feedback.classList.add('hidden'), 3000);
                }
                btn.disabled = false;
            })
            .catch(err => {
                feedback.innerHTML = '<div class="p-2 bg-red-50 border border-red-200 rounded text-red-800 text-xs">Error: ' + err.message + '</div>';
                feedback.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = currentlyEnabled ? 'Disable Qurbani Mode' : 'Enable Qurbani Mode';
            });
        }
        </script>

        <script>
        function executeSelectedImport() {
            const source = document.getElementById('importSource').value;
            const importType = document.getElementById('importType').value;
            
            closeModal('importProductsModal');
            
            const sourceName = source === 'woocommerce' ? 'WooCommerce' : 'Shopify';
            if (confirm(`This will import products from your ${sourceName} store.\n\nFor existing products (matched by SKU), only prices will be updated.\nYour categories and attributes will remain unchanged.\n\nContinue?`)) {
                executeImportAll(sourceName);
            }
        }
        
        function executeImportAll(source = 'Shopify') {
            // Show loading overlay
            const loadingOverlay = document.createElement('div');
            loadingOverlay.id = 'importAllOverlay';
            loadingOverlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0,0,0,0.8); z-index: 9999; display: flex; 
                align-items: center; justify-content: center; color: white;
            `;
            loadingOverlay.innerHTML = `
                <div style="text-align: center; background: white; padding: 40px; border-radius: 12px; color: #111827;">
                    <div style="display: inline-block; width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top: 4px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px;"></div>
                    <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600;">Importing Products</h3>
                    <p style="margin: 0; color: #6b7280;">This may take several minutes. Please don't close this window...</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
            document.body.appendChild(loadingOverlay);
            
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                             document.querySelector('input[name="_token"]')?.value || '';
            
            // Make API call
            const url = '/products/import-all' + (source ? ('?source=' + encodeURIComponent(source)) : '');
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    _token: csrfToken,
                    price_only_update: true  // Flag to update only prices for existing products
                })
            })
            .then(response => response.json())
            .then(data => {
                // Remove loading overlay
                if (document.body.contains(loadingOverlay)) {
                    document.body.removeChild(loadingOverlay);
                }
                
                if (data.success) {
                    alert(`Import completed!\n\n${data.message}\n\nTotal Products: ${data.total_products}\nNew: ${data.imported_count}\nUpdated (prices only): ${data.updated_count}\nErrors: ${data.error_count}`);
                    // Reload the page
                    window.location.reload();
                } else {
                    alert('Import failed: ' + data.message);
                }
            })
            .catch(error => {
                // Remove loading overlay
                if (document.body.contains(loadingOverlay)) {
                    document.body.removeChild(loadingOverlay);
                }
                alert('Import error: ' + error.message);
            });
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
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
        
        function toggleLedgerPosting() {
            const toggleButton = document.getElementById('toggleButton');
            const feedback = document.getElementById('toggleFeedback');
            const statusBadge = document.getElementById('statusBadge');
            
            console.log('Toggle function called');
            console.log('Button element:', toggleButton);
            
            // Determine current state from button text
            const currentlyEnabled = toggleButton.textContent.includes('Disable');
            const newState = !currentlyEnabled;
            
            console.log('Current state:', currentlyEnabled, 'New state:', newState);
            
            // Show loading state
            toggleButton.disabled = true;
            toggleButton.textContent = '⏳ Updating...';
            
            feedback.innerHTML = '<div class="p-2 bg-blue-50 border border-blue-200 rounded text-blue-800 text-xs">Updating configuration...</div>';
            feedback.classList.remove('hidden');
            
            const url = '{{ route("fin.action-items.toggle-posting") }}';
            console.log('Fetching URL:', url);
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ enabled: newState })
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                console.log('data.success:', data.success, 'type:', typeof data.success);
                console.log('data.enabled:', data.enabled, 'type:', typeof data.enabled);
                
                if (data.success === true || data.success === 'true') {
                    feedback.innerHTML = '<div class="p-2 bg-green-50 border border-green-200 rounded text-green-800 text-xs">✓ ' + data.message + '</div>';
                    
                    // Update button and badge based on the NEW state from server
                    const isNowEnabled = (data.enabled === true || data.enabled === 'true' || data.enabled === 1 || data.enabled === '1');
                    console.log('Is now enabled?', isNowEnabled);
                    
                    if (isNowEnabled) {
                        toggleButton.className = 'w-full px-4 py-3 text-sm font-semibold rounded-lg transition-colors duration-200 bg-red-600 hover:bg-red-700 text-white';
                        toggleButton.textContent = '⏸️ Disable Automatic Posting';
                        statusBadge.className = 'px-4 py-2 rounded-full text-sm font-bold bg-green-100 text-green-800';
                        statusBadge.textContent = 'ENABLED';
                    } else {
                        toggleButton.className = 'w-full px-4 py-3 text-sm font-semibold rounded-lg transition-colors duration-200 bg-green-600 hover:bg-green-700 text-white';
                        toggleButton.textContent = '▶️ Enable Automatic Posting';
                        statusBadge.className = 'px-4 py-2 rounded-full text-sm font-bold bg-gray-100 text-gray-800';
                        statusBadge.textContent = 'DISABLED';
                    }
                    
                    toggleButton.disabled = false;
                    
                    setTimeout(() => {
                        feedback.classList.add('hidden');
                    }, 3000);
                } else {
                    console.log('Success was false or error occurred');
                    feedback.innerHTML = '<div class="p-2 bg-red-50 border border-red-200 rounded text-red-800 text-xs">❌ Error: ' + (data.message || 'Unknown error') + '</div>';
                    toggleButton.disabled = false;
                    toggleButton.textContent = currentlyEnabled ? '⏸️ Disable Automatic Posting' : '▶️ Enable Automatic Posting';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                console.error('Error details:', error.message, error.stack);
                feedback.innerHTML = '<div class="p-2 bg-red-50 border border-red-200 rounded text-red-800 text-xs">❌ Connection error: ' + error.message + '</div>';
                toggleButton.disabled = false;
                toggleButton.textContent = currentlyEnabled ? '⏸️ Disable Automatic Posting' : '▶️ Enable Automatic Posting';
            });
        }
        
        function openAddExpenseCategoryModal() {
            document.getElementById('addExpenseCategoryModal').classList.remove('hidden');
            document.getElementById('addExpenseCategoryModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddExpenseCategoryModal() {
            document.getElementById('addExpenseCategoryModal').classList.add('hidden');
            document.getElementById('addExpenseCategoryModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // ⭐ GEOCODING FUNCTIONS
        let geocodingRunning = false;
        let geocodingTotal = 0;
        let geocodingDone = 0;
        
        // Load geocoding stats on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadGeocodingStats();
        });
        
        async function loadGeocodingStats() {
            const statsDiv = document.getElementById('geocodingStats');
            try {
                const response = await fetch('/customers/geocode-stats', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                
                if (data.success && data.stats) {
                    const s = data.stats;
                    statsDiv.innerHTML = `
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <span class="text-gray-500">Total Customers:</span>
                                <span class="font-semibold text-gray-800">${s.total_customers}</span>
                            </div>
                            <div>
                                <span class="text-gray-500">Verified GPS:</span>
                                <span class="font-semibold text-green-600">${s.with_verified_location}</span>
                            </div>
                            <div>
                                <span class="text-gray-500">Geocoded:</span>
                                <span class="font-semibold text-purple-600">${s.with_geocoded_location}</span>
                            </div>
                            <div>
                                <span class="text-gray-500">Need Geocoding:</span>
                                <span class="font-semibold text-orange-600">${s.needs_geocoding}</span>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-gray-500">
                            Coverage: ${s.coverage_percent}% of customers with addresses have coordinates
                        </div>
                    `;
                }
            } catch (error) {
                statsDiv.innerHTML = '<div class="text-sm text-red-600">Failed to load stats</div>';
            }
        }
        
        async function startGeocoding() {
            if (geocodingRunning) return;
            
            geocodingRunning = true;
            geocodingDone = 0;
            
            document.getElementById('startGeocodingBtn').classList.add('hidden');
            document.getElementById('stopGeocodingBtn').classList.remove('hidden');
            document.getElementById('geocodingProgress').classList.remove('hidden');
            document.getElementById('geocodingLog').innerHTML = '';
            
            await runGeocodingBatch();
        }
        
        function stopGeocoding() {
            geocodingRunning = false;
            document.getElementById('startGeocodingBtn').classList.remove('hidden');
            document.getElementById('stopGeocodingBtn').classList.add('hidden');
            addGeocodingLog('⏹️ Stopped by user');
            loadGeocodingStats();
        }
        
        async function runGeocodingBatch() {
            if (!geocodingRunning) return;
            
            try {
                // Use days=30 for customers with orders in last 30 days
                const response = await fetch('/orders/geocode-pending?limit=5&days=30', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                
                if (data.success) {
                    geocodingDone += data.geocoded + data.failed;
                    geocodingTotal = geocodingDone + data.remaining;
                    
                    // Update progress
                    const percent = geocodingTotal > 0 ? Math.round((geocodingDone / geocodingTotal) * 100) : 0;
                    document.getElementById('geocodingProgressBar').style.width = percent + '%';
                    document.getElementById('geocodingProgressText').textContent = `${geocodingDone} / ${geocodingTotal}`;
                    
                    // Log results
                    if (data.geocoded > 0 || data.failed > 0) {
                        addGeocodingLog(`✓ ${data.geocoded} success, ✗ ${data.failed} failed`);
                    }
                    
                    // Continue if more to process
                    if (data.remaining > 0 && geocodingRunning) {
                        setTimeout(runGeocodingBatch, 2000); // Wait 2 seconds between batches
                    } else {
                        // Done!
                        geocodingRunning = false;
                        document.getElementById('startGeocodingBtn').classList.remove('hidden');
                        document.getElementById('stopGeocodingBtn').classList.add('hidden');
                        addGeocodingLog('✅ Geocoding complete!');
                        loadGeocodingStats();
                    }
                } else {
                    addGeocodingLog('❌ Error: ' + (data.message || 'Unknown error'));
                    stopGeocoding();
                }
            } catch (error) {
                addGeocodingLog('❌ Connection error: ' + error.message);
                stopGeocoding();
            }
        }
        
        function addGeocodingLog(message) {
            const log = document.getElementById('geocodingLog');
            const time = new Date().toLocaleTimeString();
            log.innerHTML = `<div>[${time}] ${message}</div>` + log.innerHTML;
        }
        
        // ⭐ HISTORY IMPORT FUNCTION
        async function startHistoryImport() {
            if (!confirm('This will import all historical orders from the CSV file.\n\nThis may take 5-10 minutes for large files.\n\nContinue?')) {
                return;
            }
            
            const btn = document.getElementById('startHistoryImportBtn');
            const progress = document.getElementById('historyImportProgress');
            const result = document.getElementById('historyImportResult');
            const status = document.getElementById('historyImportStatus');
            
            // Show progress, hide result
            btn.disabled = true;
            btn.textContent = '⏳ Importing...';
            progress.classList.remove('hidden');
            result.classList.add('hidden');
            status.textContent = 'Processing CSV file...';
            
            try {
                const response = await fetch('{{ route("operations.history-import") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                
                const data = await response.json();
                
                // Hide progress
                progress.classList.add('hidden');
                
                // Show result
                result.classList.remove('hidden');
                
                if (data.success) {
                    result.className = 'mb-4 p-3 rounded-lg bg-green-50 border border-green-200';
                    
                    // Format database summary
                    let summaryHtml = '';
                    if (data.database_summary) {
                        const s = data.database_summary;
                        summaryHtml = `
                            <div class="mt-3 pt-3 border-t border-green-300">
                                <strong>📊 Database Now Contains:</strong>
                                <div class="text-xs mt-1">
                                    <div>Total orders: <strong>${s.total_history_orders?.toLocaleString() || 0}</strong></div>
                                    <div>Total line items: <strong>${s.total_history_line_items?.toLocaleString() || 0}</strong></div>
                                    <div>Total revenue: <strong>PKR ${Number(s.total_history_revenue || 0).toLocaleString()}</strong></div>
                                    <div>Date range: ${s.date_range?.earliest || 'N/A'} → ${s.date_range?.latest || 'N/A'}</div>
                                </div>
                            </div>`;
                    }
                    
                    result.innerHTML = `
                        <div class="text-green-800">
                            <strong>✅ ${data.message}</strong>
                            <div class="mt-2 text-sm">
                                <div>📦 Orders created: <strong>${data.stats.orders_created}</strong></div>
                                <div>🔄 Duplicates skipped: <strong>${data.stats.orders_skipped_duplicate || 0}</strong></div>
                                <div>📋 Line items: <strong>${data.stats.line_items_created}</strong></div>
                                <div>👤 New customers: <strong>${data.stats.customers_created}</strong></div>
                                <div>👤 Updated customers: <strong>${data.stats.customers_updated}</strong></div>
                                <div>⏭️ Skipped rows: ${data.stats.skipped_rows}</div>
                                ${data.stats.errors && data.stats.errors.length > 0 ? 
                                    `<div class="mt-2 text-orange-700">⚠️ Errors: ${data.stats.errors.length}</div>` : ''}
                                ${summaryHtml}
                            </div>
                            <div class="mt-2 text-xs text-gray-600">Batch ID: ${data.batch_id}</div>
                        </div>
                    `;
                } else {
                    result.className = 'mb-4 p-3 rounded-lg bg-red-50 border border-red-200';
                    result.innerHTML = `
                        <div class="text-red-800">
                            <strong>❌ Import Failed</strong>
                            <div class="mt-2 text-sm">${data.message}</div>
                        </div>
                    `;
                }
                
            } catch (error) {
                progress.classList.add('hidden');
                result.classList.remove('hidden');
                result.className = 'mb-4 p-3 rounded-lg bg-red-50 border border-red-200';
                result.innerHTML = `
                    <div class="text-red-800">
                        <strong>❌ Connection Error</strong>
                        <div class="mt-2 text-sm">${error.message}</div>
                    </div>
                `;
            }
            
            // Re-enable button
            btn.disabled = false;
            btn.textContent = '📥 Start History Import';
        }
        </script>
    </div>
</div>

<!-- Add Expense Category Modal -->
<div id="addExpenseCategoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">➕ Add New Expense Category</h2>
                <button onclick="closeAddExpenseCategoryModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form action="{{ route('fin.expense-category.store') }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                        <input type="text" name="category_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                               placeholder="e.g., Petrol, Office Supplies, Rent">
                    </div>
                    
                    <div class="p-3 bg-purple-50 border border-purple-200 rounded-md">
                        <p class="text-xs text-purple-800">
                            ℹ️ <strong>System will automatically:</strong>
                        </p>
                        <ul class="text-xs text-purple-700 mt-1 ml-4 list-disc">
                            <li>Create an expense account (e.g., EXP_PETROL)</li>
                            <li>Set account type as "Expense"</li>
                            <li>Make it available in expense request forms</li>
                        </ul>
                    </div>
                    
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeAddExpenseCategoryModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md">
                            ✓ Create Category
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Asset Category Modal -->
<div id="addAssetCategoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">➕ Add New Asset Category</h2>
                <button onclick="closeAddAssetCategoryModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form id="addAssetCategoryForm" onsubmit="submitAssetCategory(event)">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                        <input type="text" name="category_name" id="assetCategoryName" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500"
                               placeholder="e.g., Machinery, Vehicles, Computers">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Useful Life (Years)</label>
                        <input type="number" name="useful_life_years" id="assetUsefulLife" min="1" max="50"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500"
                               placeholder="e.g., 5" value="5">
                        <p class="text-xs text-gray-500 mt-1">Default useful life for depreciation calculation (optional)</p>
                    </div>
                    
                    <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-md">
                        <p class="text-xs text-emerald-800">
                            ℹ️ <strong>This will:</strong>
                        </p>
                        <ul class="text-xs text-emerald-700 mt-1 ml-4 list-disc">
                            <li>Create a new asset category for classification</li>
                            <li>Make it available when adding new company assets</li>
                        </ul>
                    </div>
                    
                    <div id="assetCategoryFeedback" class="hidden p-3 rounded-md"></div>
                    
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeAddAssetCategoryModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" id="assetCategorySubmitBtn" class="flex-1 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md">
                            ✓ Create Category
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddAssetCategoryModal() {
    document.getElementById('addAssetCategoryModal').classList.remove('hidden');
    document.getElementById('addAssetCategoryModal').style.display = 'flex';
    document.getElementById('assetCategoryName').focus();
}

function closeAddAssetCategoryModal() {
    document.getElementById('addAssetCategoryModal').classList.add('hidden');
    document.getElementById('addAssetCategoryModal').style.display = 'none';
    document.getElementById('assetCategoryName').value = '';
    document.getElementById('assetUsefulLife').value = '5';
    document.getElementById('assetCategoryFeedback').classList.add('hidden');
}

function submitAssetCategory(event) {
    event.preventDefault();
    
    const name = document.getElementById('assetCategoryName').value.trim();
    const usefulLife = document.getElementById('assetUsefulLife').value;
    const feedback = document.getElementById('assetCategoryFeedback');
    const submitBtn = document.getElementById('assetCategorySubmitBtn');
    
    if (!name) {
        feedback.className = 'p-3 rounded-md bg-red-50 border border-red-200';
        feedback.innerHTML = '<p class="text-sm text-red-700">❌ Category name is required</p>';
        feedback.classList.remove('hidden');
        return;
    }
    
    // Disable button during submission
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Creating...';
    
    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                     document.querySelector('input[name="_token"]')?.value || '';
    
    fetch('{{ route("fin.asset-category.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({
            _token: csrfToken,
            category_name: name,
            useful_life_years: usefulLife || null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            feedback.className = 'p-3 rounded-md bg-green-50 border border-green-200';
            feedback.innerHTML = `<p class="text-sm text-green-700">✅ ${data.message}</p>`;
            feedback.classList.remove('hidden');
            
            // Refresh page after 1 second to show new category
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            feedback.className = 'p-3 rounded-md bg-red-50 border border-red-200';
            feedback.innerHTML = `<p class="text-sm text-red-700">❌ ${data.message || 'Error creating category'}</p>`;
            feedback.classList.remove('hidden');
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = '✓ Create Category';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        feedback.className = 'p-3 rounded-md bg-red-50 border border-red-200';
        feedback.innerHTML = '<p class="text-sm text-red-700">❌ An error occurred. Please try again.</p>';
        feedback.classList.remove('hidden');
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = '✓ Create Category';
    });
}
</script>

<!-- Include the same Import modal markup used on Orders page -->
@include('pages.orders.partials.import-modal')
@include('pages.products.partials.import-products-modal')

@endsection

