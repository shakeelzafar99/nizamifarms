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
        // Normalize header names
        $map = array_flip(array_map('strtolower', $header));

        $ok = 0; $skipped = 0; $errors = []; $missingOrders = []; $missingRiders = [];
        foreach ($rows as $i => $row) {
            try {
                $data = [];
                $data['order_number'] = $row[$map['order_number'] ?? -1] ?? null;
                $data['rider_name']   = $row[$map['rider_name'] ?? -1] ?? null;
                $data['rider_phone']  = $row[$map['rider_phone'] ?? -1] ?? null;
                $data['assigned_at']  = $row[$map['assigned_at'] ?? -1] ?? null;

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

                // Resolve rider user id
                $rider = DB::table('t_sys_user');
                if ($data['rider_phone']) {
                    $rider->where('email', $data['rider_phone'])->orWhere('fullname', 'like', $data['rider_name'].'%');
                } else {
                    $rider->where('fullname', 'like', $data['rider_name'].'%');
                }
                $rider = $rider->first();
                if (!$rider) { 
                    $missingRiders[] = $data['rider_name'] ?: $data['rider_phone'];
                    continue; 
                }

                // Use model method for assignment
                $model = OrderModel::find($order->id);
                $assignedAt = $data['assigned_at'] ? new \DateTime($data['assigned_at']) : null;
                $ok += $model && $model->assignRider((int)$rider->id, 'CSV import', null, $assignedAt) ? 1 : 0;
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

        $ok = 0; $skipped = 0; $errors = []; $missingEmployees = [];
        foreach ($rows as $i => $row) {
            try {
                $date = $row[$map['date'] ?? -1] ?? null;
                $employee = $row[$map['employee'] ?? -1] ?? ($row[$map['employee_name'] ?? -1] ?? null);
                $loginTime = $row[$map['login time'] ?? -1] ?? ($row[$map['login_time'] ?? -1] ?? null);
                $logoutTime = $row[$map['log out time'] ?? -1] ?? ($row[$map['logout time'] ?? -1] ?? null);
                $loginLoc = $row[$map['login location'] ?? -1] ?? null;   // "lat, lng" or two separate columns in legacy
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

                // Resolve user by name (fullname like). You can switch to a phone/email field when consistent.
                $user = DB::table('t_sys_user')->where('fullname','like',$employee.'%')->first();
                if (!$user) { 
                    $missingEmployees[] = $employee;
                    continue; 
                }

                // Parse lat,lng from a single cell like "33.7, 73.0"
                [$loginLat,$loginLng] = $this->splitLatLng($loginLoc);
                [$logoutLat,$logoutLng] = $this->splitLatLng($logoutLoc);

                DB::table('t_ops_attendance')->insert([
                    'user_id' => $user->id,
                    'attendance_date' => date('Y-m-d', strtotime($date)),
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
                    'notes' => 'CSV import'
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i+2).': '.$e->getMessage();
            }
        }

        $html = '<div class="text-green-700">Imported: '.$ok.' row(s).</div>';
        if ($missingEmployees) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Employees not found in users:</strong><br>'.implode(', ', array_unique($missingEmployees)).'</div>';
        }
        if ($errors) {
            $html .= '<div class="mt-2 text-red-600"><strong>Processing errors:</strong><br>'.implode('<br>', array_map('htmlspecialchars',$errors)).'</div>';
        }
        return back()->with('attendance_import_result', $html);
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


