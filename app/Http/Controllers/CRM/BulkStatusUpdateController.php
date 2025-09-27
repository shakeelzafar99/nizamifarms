<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\Storage;

class BulkStatusUpdateController extends Controller
{
    public function showUploadForm()
    {
        return view('admin.bulk-status-update');
    }

    public function processUpload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048'
        ]);

        try {
            $file = $request->file('csv_file');
            $csvData = array_map('str_getcsv', file($file->getRealPath()));
            
            // Remove header row
            $header = array_shift($csvData);
            
            $results = [
                'updated' => 0,
                'not_found' => 0,
                'errors' => [],
                'updated_orders' => []
            ];

            foreach ($csvData as $row) {
                if (count($row) < 2) continue; // Skip incomplete rows
                
                $orderNumber = trim($row[0]);
                $status = strtolower(trim($row[1]));
                
                if ($status === 'delivered') {
                    $order = OrderModel::where('order_number', $orderNumber)->first();
                    
                    if ($order) {
                        try {
                            // Use the changeStatus method to update with history
                            if (method_exists($order, 'changeStatus')) {
                                $success = $order->changeStatus('delivered', 'Bulk update from CSV import');
                                if ($success) {
                                    $results['updated']++;
                                    $results['updated_orders'][] = $orderNumber;
                                } else {
                                    $results['errors'][] = "Failed to update order {$orderNumber}";
                                }
                            } else {
                                $order->order_status = 'delivered';
                                $order->save();
                                $results['updated']++;
                                $results['updated_orders'][] = $orderNumber;
                            }
                        } catch (\Exception $e) {
                            $results['errors'][] = "Error updating order {$orderNumber}: " . $e->getMessage();
                        }
                    } else {
                        $results['not_found']++;
                        $results['errors'][] = "Order not found: {$orderNumber}";
                    }
                }
            }

            return view('admin.bulk-status-update', compact('results'));

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Error processing file: ' . $e->getMessage()]);
        }
    }
}
