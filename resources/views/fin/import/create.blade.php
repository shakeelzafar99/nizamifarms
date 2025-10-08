@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Import Legacy Data</h1>
        <a href="{{ route('admin.operations') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Operations
        </a>
    </div>

    <!-- Instructions Card -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
        <h3 class="text-lg font-semibold text-blue-900 mb-3">📊 Before You Import</h3>
        <div class="text-sm text-blue-800 space-y-2">
            <p><strong>This will import:</strong></p>
            <ul class="list-disc list-inside ml-4 space-y-1">
                <li>Employee invoices & cash balances</li>
                <li>Vendor purchases & payables</li>
                <li>Expense transactions by category</li>
                <li>Employee deposits to NF Cash</li>
            </ul>
            
            <p class="mt-4"><strong>⚠️ Important Notes:</strong></p>
            <ul class="list-disc list-inside ml-4 space-y-1">
                <li>Employees must exist in the user table (name matching)</li>
                <li>Unmatched employees will be listed for you to add</li>
                <li>Safe to re-run - duplicate records are automatically skipped</li>
                <li>Processing may take 1-2 minutes for large files</li>
            </ul>
        </div>
    </div>

    <!-- Upload Form -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Upload CSV File</h3>
        
        <form action="{{ route('fin.import.legacy') }}" method="POST" enctype="multipart/form-data">
            @csrf
            
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Select Legacy Expense Sheet CSV
                </label>
                <input 
                    type="file" 
                    name="csv_file" 
                    accept=".csv,.txt" 
                    required
                    class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100"
                >
                <p class="mt-2 text-xs text-gray-500">
                    CSV file with columns: date, Name, category, mode, type, Amount, approval status, etc.
                </p>
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('fin.import.template') }}" class="text-sm text-blue-600 hover:text-blue-800">
                    📥 Download CSV Template
                </a>
                
                <button 
                    type="submit" 
                    class="inline-flex items-center px-6 py-3 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                >
                    📤 Upload & Import
                </button>
            </div>
        </form>

        @if(session('import_result'))
            <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded">
                {!! session('import_result') !!}
            </div>
        @endif
    </div>

    <!-- View Results -->
    <div class="mt-6 text-center">
        <a href="{{ route('fin.import.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            View Import History →
        </a>
    </div>
</div>
@endsection

