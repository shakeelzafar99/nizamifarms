@extends('layouts.app')

@section('title', 'Import Details')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Import Details</h1>
        <a href="{{ route('fin.import.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Import History
        </a>
    </div>

    <!-- Import Summary Card -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-3">Import Information</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">File Name:</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $import->file_name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Import Date:</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $import->import_date ? $import->import_date->format('M j, Y g:i A') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Imported By:</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $import->importedBy ? $import->importedBy->name : 'System' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Source:</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $import->import_source }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Status:</dt>
                        <dd>
                            @php
                                $statusColors = [
                                    'completed' => 'bg-green-100 text-green-800',
                                    'partial' => 'bg-yellow-100 text-yellow-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'in_progress' => 'bg-blue-100 text-blue-800',
                                ];
                                $color = $statusColors[$import->status] ?? 'bg-gray-100 text-gray-800';
                            @endphp
                            <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $color }}">
                                {{ ucfirst(str_replace('_', ' ', $import->status)) }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-3">Processing Statistics</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Total Rows:</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ number_format($import->rows_processed) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Successfully Imported:</dt>
                        <dd class="text-sm font-medium text-green-600">{{ number_format($import->rows_inserted) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Skipped (Duplicates):</dt>
                        <dd class="text-sm font-medium text-yellow-600">{{ number_format($import->rows_skipped) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-600">Failed:</dt>
                        <dd class="text-sm font-medium text-red-600">{{ number_format($import->rows_failed) }}</dd>
                    </div>
                    <div class="flex justify-between pt-2 border-t border-gray-200">
                        <dt class="text-sm font-semibold text-gray-700">Success Rate:</dt>
                        <dd class="text-sm font-bold text-gray-900">{{ $import->formatted_success_rate }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

    <!-- Detailed Summary -->
    @if($import->summary)
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Transaction Breakdown</h3>
            
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                @if(isset($import->summary['invoices']))
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-2xl font-bold text-green-700">{{ $import->summary['invoices'] }}</div>
                        <div class="text-xs text-green-600 mt-1">Invoices</div>
                    </div>
                @endif
                
                @if(isset($import->summary['expenses']))
                    <div class="text-center p-4 bg-red-50 rounded-lg">
                        <div class="text-2xl font-bold text-red-700">{{ $import->summary['expenses'] }}</div>
                        <div class="text-xs text-red-600 mt-1">Expenses</div>
                    </div>
                @endif
                
                @if(isset($import->summary['vendor_purchases']))
                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                        <div class="text-2xl font-bold text-purple-700">{{ $import->summary['vendor_purchases'] }}</div>
                        <div class="text-xs text-purple-600 mt-1">Vendor Purchases</div>
                    </div>
                @endif
                
                @if(isset($import->summary['vendor_payments']))
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-2xl font-bold text-blue-700">{{ $import->summary['vendor_payments'] }}</div>
                        <div class="text-xs text-blue-600 mt-1">Vendor Payments</div>
                    </div>
                @endif
                
                @if(isset($import->summary['deposits']))
                    <div class="text-center p-4 bg-indigo-50 rounded-lg">
                        <div class="text-2xl font-bold text-indigo-700">{{ $import->summary['deposits'] }}</div>
                        <div class="text-xs text-indigo-600 mt-1">Deposits</div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Unmatched Employees -->
    @if($import->summary && isset($import->summary['unmatched_employees']) && count($import->summary['unmatched_employees']) > 0)
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
            <h3 class="text-lg font-medium text-yellow-900 mb-3">⚠️ Unmatched Employees</h3>
            <p class="text-sm text-yellow-700 mb-3">
                The following employees were not found in the user table. 
                {{ $import->summary['skipped_records_count'] ?? 0 }} records were skipped.
            </p>
            <ul class="list-disc list-inside space-y-1">
                @foreach($import->summary['unmatched_employees'] as $employee)
                    <li class="text-sm text-yellow-800">{{ $employee }}</li>
                @endforeach
            </ul>
            <div class="mt-4 p-3 bg-yellow-100 rounded">
                <p class="text-xs text-yellow-800">
                    <strong>Action Required:</strong> Add these employees to the user table and re-run the import to process their transactions.
                </p>
            </div>
        </div>
    @endif

    <!-- Error Details -->
    @if($import->error_details)
        <div class="bg-red-50 border border-red-200 rounded-lg p-6">
            <h3 class="text-lg font-medium text-red-900 mb-3">Error Details</h3>
            <div class="text-sm text-red-700">
                @if(is_array($import->error_details))
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($import->error_details as $key => $value)
                            <li><strong>{{ $key }}:</strong> {{ $value }}</li>
                        @endforeach
                    </ul>
                @else
                    {{ $import->error_details }}
                @endif
            </div>
        </div>
    @endif
</div>
@endsection

