@extends('layouts.app')

@section('title', 'Bulk Status Update')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Bulk Order Status Update</h1>
            
            @if(isset($results))
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <h3 class="text-lg font-semibold text-green-800 mb-2">Update Results</h3>
                    <div class="space-y-2">
                        <p class="text-green-700">✅ Updated: <strong>{{ $results['updated'] }}</strong> orders</p>
                        <p class="text-orange-700">⚠️ Not found: <strong>{{ $results['not_found'] }}</strong> orders</p>
                        
                        @if(!empty($results['updated_orders']))
                            <details class="mt-4">
                                <summary class="cursor-pointer text-green-700 font-medium">View Updated Orders</summary>
                                <div class="mt-2 p-3 bg-white border rounded">
                                    @foreach($results['updated_orders'] as $orderNumber)
                                        <span class="inline-block bg-green-100 text-green-800 px-2 py-1 rounded text-sm mr-2 mb-1">{{ $orderNumber }}</span>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                        
                        @if(!empty($results['errors']))
                            <details class="mt-4">
                                <summary class="cursor-pointer text-red-700 font-medium">View Errors ({{ count($results['errors']) }})</summary>
                                <div class="mt-2 p-3 bg-red-50 border border-red-200 rounded">
                                    @foreach($results['errors'] as $error)
                                        <p class="text-red-700 text-sm">• {{ $error }}</p>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <h3 class="text-lg font-semibold text-red-800 mb-2">Errors</h3>
                    @foreach($errors->all() as $error)
                        <p class="text-red-700">{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h3 class="text-lg font-semibold text-blue-800 mb-2">Instructions</h3>
                <ol class="list-decimal list-inside text-blue-700 space-y-1">
                    <li>Save your Google Sheets as CSV file</li>
                    <li>Make sure the first column is "order_no" and second column is "status"</li>
                    <li>Only orders with status "delivered" will be updated</li>
                    <li>Upload the CSV file below</li>
                </ol>
            </div>

            <form action="{{ route('admin.bulk-status-update.process') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                
                <div>
                    <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">
                        Select CSV File
                    </label>
                    <input type="file" 
                           id="csv_file" 
                           name="csv_file" 
                           accept=".csv,.txt"
                           required
                           class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                </div>

                <div class="flex justify-end">
                    <button type="submit" 
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Upload and Process
                    </button>
                </div>
            </form>

            <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                <h3 class="text-sm font-semibold text-gray-800 mb-2">Expected CSV Format:</h3>
                <pre class="text-xs text-gray-600 bg-white p-2 rounded border">order_no,status,date
4602,delivered,11/18/2024 15
4595,delivered,11/18/2024 15
4634,delivered,11/18/2024 15</pre>
            </div>
        </div>
    </div>
</div>
@endsection
