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
                $dateStr = isset($row[2]) ? trim($row[2]) : null; // optional changed_at from sheet
                
                if ($status === 'delivered') {
                    $order = OrderModel::where('order_number', $orderNumber)->first();

                    if ($order) {
                        try {
                            // 🚚 A row for an order still ON THE VAN is reported, not
                            // applied — if the sheet says delivered while the system
                            // says on-van, one of them is wrong and a human should
                            // look, not have the import silently pick a winner.
                            $vanBlock = \App\Services\Riders\VanService::manualChangeBlock($order, 'delivered');
                            if ($vanBlock !== null) {
                                $results['errors'][] = "Order {$orderNumber} skipped: {$vanBlock}";
                                continue;
                            }

                            // Use the changeStatus method to update with history
                            if (method_exists($order, 'changeStatus')) {
                                $success = $order->changeStatus('delivered', 'Bulk update from CSV import');
                                
                                // If a date was provided, override the changed_at/created_at of the latest current row
                                if ($success && $dateStr) {
                                    try {
                                        $dt = new \DateTime($dateStr);
                                        \DB::table('t_crm_order_status_history')
                                            ->where('order_id', $order->id)
                                            ->where('is_current', 1)
                                            ->orderByDesc('id')
                                            ->limit(1)
                                            ->update([
                                                'changed_at' => $dt->format('Y-m-d H:i:s'),
                                                'created_at' => $dt->format('Y-m-d H:i:s'),
                                            ]);
                                        // Reconcile current flag and main order based on latest changed_at
                                        \App\Models\CRM\OrderModel::reconcileCurrentStatus($order->id);
                                    } catch (\Throwable $e) {
                                        $results['errors'][] = "Date parse/override failed for order {$orderNumber}: " . $e->getMessage();
                                    }
                                }

                                if ($success) {
                                    $results['updated']++;
                                    $results['updated_orders'][] = $orderNumber;
                                } else {
                                    $results['errors'][] = "Failed to update order {$orderNumber}";
                                }
                            } else {
                                $order->order_status = 'delivered';
                                $order->save();
                                // If date provided, there is no history method here; record a history row manually
                                if ($dateStr) {
                                    try {
                                        $dt = new \DateTime($dateStr);
                                        $statusId = \DB::table('t_crm_order_status_master')->where('status_code', 'delivered')->value('id');
                                        if ($statusId) {
                                            // Demote any current row then insert new
                                            \DB::table('t_crm_order_status_history')
                                                ->where('order_id', $order->id)
                                                ->where('is_current', 1)
                                                ->update(['is_current' => 0]);
                                            \DB::table('t_crm_order_status_history')->insert([
                                                'order_id' => $order->id,
                                                'status_id' => $statusId,
                                                'status_code' => 'delivered',
                                                'is_current' => 1,
                                                'changed_by' => auth()->check() ? auth()->id() : 1,
                                                'notes' => 'Bulk update from CSV import',
                                                'changed_at' => $dt->format('Y-m-d H:i:s'),
                                                'created_at' => $dt->format('Y-m-d H:i:s'),
                                            ]);
                                        }
                                    } catch (\Throwable $e) {
                                        $results['errors'][] = "Manual history insert failed for order {$orderNumber}: " . $e->getMessage();
                                    }
                                }
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
