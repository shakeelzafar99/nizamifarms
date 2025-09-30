<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use Illuminate\Http\Request;

class OrderRiderController extends Controller
{
    public function assign(Request $request, int $orderId)
    {
        $data = $request->validate([
            'rider_user_id' => 'required|integer',
            'notes' => 'nullable|string',
            'assigned_at' => 'nullable|date',
        ]);

        /** @var OrderModel|null $order */
        $order = OrderModel::find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $assignedAt = isset($data['assigned_at']) ? new \DateTime($data['assigned_at']) : null;
        $ok = $order->assignRider((int)$data['rider_user_id'], $data['notes'] ?? null, null, $assignedAt);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'Assignment failed'], 500);
        }

        return response()->json(['success' => true]);
    }

    public function timeline(int $orderId)
    {
        $rows = \DB::table('t_ops_order_rider_history as h')
            ->join('t_sys_user as u', 'u.id', '=', 'h.rider_user_id')
            ->leftJoin('t_sys_user as a', 'a.id', '=', 'h.assigned_by')
            ->where('h.order_id', $orderId)
            ->orderByDesc('h.assigned_at')
            ->limit(20)
            ->get([
                'h.rider_user_id',
                'u.fullname as rider_name',
                'h.assigned_at',
                'h.unassigned_at',
                'h.is_current',
                'h.source',
                'h.notes',
                'a.fullname as assigned_by_name',
            ]);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }
}


