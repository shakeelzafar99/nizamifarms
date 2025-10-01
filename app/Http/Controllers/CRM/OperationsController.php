<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CRM\OrderModel;

class OperationsController extends Controller
{
    public function importRiderAssignments(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:4096'
        ]);

        $file = $request->file('csv_file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        if (!$rows || count($rows) < 2) {
            return back()->with('rider_import_result', '<div class="text-red-600">Empty or invalid CSV</div>');
        }
        // Remove header row
        $header = array_map('trim', array_shift($rows));
        // Normalize header names (accept multiple formats)
        $map = array_flip(array_map('strtolower', $header));

        $ok = 0; $skipped = 0; $errors = []; $missingOrders = []; $missingRiders = [];
        foreach ($rows as $i => $row) {
            try {
                $data = [];
                // Accept multiple column name formats
                $data['order_number'] = $row[$map['order_number'] ?? $map['order number'] ?? -1] ?? null;
                $data['rider_name']   = $row[$map['rider_name'] ?? $map['rider name'] ?? $map['delivery_rider'] ?? $map['delivery rider'] ?? -1] ?? null;
                $data['rider_phone']  = $row[$map['rider_phone'] ?? $map['rider phone'] ?? -1] ?? null;
                $data['assigned_at']  = $row[$map['assigned_at'] ?? $map['assigned at'] ?? $map['date'] ?? -1] ?? null;

                // Trim whitespace
                $data['order_number'] = trim($data['order_number'] ?? '');
                $data['rider_name'] = trim($data['rider_name'] ?? '');

                if (!$data['order_number'] || (!$data['rider_name'] && !$data['rider_phone'])) { 
                    $errors[] = 'Row '.($i+2).': Missing order_number or rider info';
                    continue; 
                }

                // Resolve order (non-shopify only)
                $order = DB::table('t_crm_prod_order')
                    ->where(function($q){ $q->whereNull('external_source')->orWhere('external_source','!=','shopify'); })
                    ->where('order_number', $data['order_number'])
                    ->first();
                if (!$order) { 
                    $missingOrders[] = $data['order_number'];
                    continue; 
                }

                // Clean rider name (remove suffixes like "- indrive", "- Indri", etc.)
                $cleanRiderName = $this->cleanEmployeeName($data['rider_name']);

                // Resolve rider user id using smart matching (same as attendance import)
                $rider = $this->findUserByName($cleanRiderName);
                
                if (!$rider) { 
                    $missingRiders[] = $data['rider_name'] . ' (cleaned: ' . $cleanRiderName . ')';
                    continue; 
                }

                // Use model method for assignment
                $model = OrderModel::find($order->id);
                $assignedAt = $data['assigned_at'] ? new \DateTime($data['assigned_at']) : null;
                $success = $model && $model->assignRider((int)$rider->id, 'CSV import', null, $assignedAt);
                
                if ($success) {
                    $ok++;
                } else {
                    $errors[] = 'Row '.($i+2).': Failed to assign rider (order: '.$data['order_number'].')';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i+2).': '.$e->getMessage();
            }
        }

        $html = '<div class="text-green-700">Imported: '.$ok.' row(s).</div>';
        if ($missingOrders) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Orders not found (non-Shopify):</strong><br>'.implode(', ', array_unique($missingOrders)).'</div>';
        }
        if ($missingRiders) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Riders not found in users:</strong><br>'.implode(', ', array_unique($missingRiders)).'</div>';
        }
        if ($errors) {
            $html .= '<div class="mt-2 text-red-600"><strong>Processing errors:</strong><br>'.implode('<br>', array_map('htmlspecialchars',$errors)).'</div>';
        }
        return back()->with('rider_import_result', $html);
    }

    public function importAttendance(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:8192'
        ]);

        $rows = array_map('str_getcsv', file($request->file('csv_file')->getRealPath()));
        if (!$rows || count($rows) < 2) {
            return back()->with('attendance_import_result', '<div class="text-red-600">Empty or invalid CSV</div>');
        }
        $header = array_map('trim', array_shift($rows));
        $map = array_flip(array_map('strtolower', $header));

        $ok = 0; $updated = 0; $skipped = 0; $errors = []; $missingEmployees = [];
        $loggedInUserId = auth()->id() ?? 1; // Default to admin if not authenticated
        
        foreach ($rows as $i => $row) {
            try {
                $date = $row[$map['date'] ?? -1] ?? null;
                $employee = $row[$map['employee'] ?? -1] ?? ($row[$map['employee_name'] ?? -1] ?? null);
                $loginTime = $row[$map['login time'] ?? -1] ?? ($row[$map['login_time'] ?? -1] ?? null);
                $logoutTime = $row[$map['log out time'] ?? -1] ?? ($row[$map['logout time'] ?? -1] ?? ($row[$map['logout_time'] ?? -1] ?? null));
                $loginLoc = $row[$map['login location'] ?? -1] ?? null;
                $logoutLoc = $row[$map['logout location'] ?? -1] ?? null;
                $device = $row[$map['device id'] ?? -1] ?? ($row[$map['device_id'] ?? -1] ?? null);
                $meterStart = $row[$map['meter start'] ?? -1] ?? null;
                $meterEnd = $row[$map['meter end'] ?? -1] ?? null;
                $picStart = $row[$map['picture start'] ?? -1] ?? null;
                $picEnd = $row[$map['picture end'] ?? -1] ?? null;

                if (!$date || !$employee) { 
                    $errors[] = 'Row '.($i+2).': Missing date or employee';
                    continue; 
                }

                // Clean employee name (remove suffixes like "- indrive", extra spaces, etc.)
                $cleanName = $this->cleanEmployeeName($employee);

                // Try multiple matching strategies
                $user = $this->findUserByName($cleanName);
                
                if (!$user) { 
                    $missingEmployees[] = $employee . ' (cleaned: ' . $cleanName . ')';
                    continue; 
                }

                // Parse lat,lng from a single cell like "33.7, 73.0"
                [$loginLat,$loginLng] = $this->splitLatLng($loginLoc);
                [$logoutLat,$logoutLng] = $this->splitLatLng($logoutLoc);

                // Parse date properly
                $attendanceDate = date('Y-m-d', strtotime($date));
                
                // Use updateOrInsert to avoid duplicates
                $existing = DB::table('t_ops_attendance')
                    ->where('user_id', $user->id)
                    ->where('attendance_date', $attendanceDate)
                    ->exists();

                DB::table('t_ops_attendance')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'attendance_date' => $attendanceDate
                    ],
                    [
                        'login_time' => $loginTime ?: null,
                        'login_lat' => $loginLat,
                        'login_lng' => $loginLng,
                        'logout_time' => $logoutTime ?: null,
                        'logout_lat' => $logoutLat,
                        'logout_lng' => $logoutLng,
                        'device_id' => $device,
                        'meter_start' => is_numeric($meterStart) ? (int)$meterStart : null,
                        'meter_end' => is_numeric($meterEnd) ? (int)$meterEnd : null,
                        'picture_start' => $picStart,
                        'picture_end' => $picEnd,
                        'notes' => 'Legacy CSV import',
                        'created_at' => $existing ? DB::raw('created_at') : now(),
                        'created_by' => $existing ? DB::raw('created_by') : $loggedInUserId,
                        'updated_by' => $loggedInUserId,
                        'updated_at' => now()
                    ]
                );
                
                if ($existing) {
                    $updated++;
                } else {
                    $ok++;
                }
                
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i+2).': '.$e->getMessage();
            }
        }

        $html = '<div class="text-green-700">';
        $html .= '<strong>Import Complete!</strong><br>';
        $html .= 'New records: '.$ok.'<br>';
        $html .= 'Updated records: '.$updated.'<br>';
        $html .= 'Total processed: '.($ok + $updated);
        $html .= '</div>';
        
        if ($missingEmployees) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Employees not found in users ('.count(array_unique($missingEmployees)).'):</strong><br>';
            $html .= '<div style="max-height: 200px; overflow-y: auto; background: #fffbeb; padding: 8px; border-radius: 4px; margin-top: 4px;">';
            $html .= implode('<br>', array_unique($missingEmployees));
            $html .= '</div></div>';
        }
        if ($errors) {
            $html .= '<div class="mt-2 text-red-600"><strong>Processing errors ('.count($errors).'):</strong><br>';
            $html .= '<div style="max-height: 200px; overflow-y: auto; background: #fef2f2; padding: 8px; border-radius: 4px; margin-top: 4px;">';
            $html .= implode('<br>', array_map('htmlspecialchars',$errors));
            $html .= '</div></div>';
        }
        return back()->with('attendance_import_result', $html);
    }

    // Helper to clean employee names
    private function cleanEmployeeName($name)
    {
        // Remove common suffixes
        $name = preg_replace('/\s*-\s*(indrive|indriver|indri)/i', '', $name);
        
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Trim
        $name = trim($name);
        
        return $name;
    }

    // Helper to find user by name with multiple strategies
    // Searches ALL users (active and inactive) - historical data may reference inactive users
    private function findUserByName($name)
    {
        // Strategy 1: Exact match
        $user = DB::table('t_sys_user')->where('fullname', $name)->first();
        if ($user) return $user;

        // Strategy 2: Case-insensitive exact match
        $user = DB::table('t_sys_user')->whereRaw('LOWER(fullname) = ?', [strtolower($name)])->first();
        if ($user) return $user;

        // Strategy 3: LIKE match (starts with)
        $user = DB::table('t_sys_user')->where('fullname', 'like', $name.'%')->first();
        if ($user) return $user;

        // Strategy 4: Contains match
        $user = DB::table('t_sys_user')->where('fullname', 'like', '%'.$name.'%')->first();
        if ($user) return $user;

        return null;
    }

    private function splitLatLng($cell)
    {
        if (!$cell) return [null,null];
        if (strpos($cell, ',') !== false) {
            $parts = array_map('trim', explode(',', $cell));
            return [is_numeric($parts[0]??null)?(float)$parts[0]:null, is_numeric($parts[1]??null)?(float)$parts[1]:null];
        }
        return [null,null];
    }
}


