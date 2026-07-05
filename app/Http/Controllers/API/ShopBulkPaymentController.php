<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Mobile API endpoint for bulk shop payments.
 *
 * Thin validate-and-delegate wrapper over the shared ShopBulkPaymentService —
 * the SAME service the web endpoint (CRM\OrderController::bulkShopPayment) uses,
 * so the FIFO allocation, validation, and ledger posting are identical on both
 * web and mobile. Auth is enforced by the route group's `auth:sanctum`
 * middleware; the service itself guards that the invoices belong to one shop
 * customer.
 *
 * POST /api/rider/orders/bulk-shop-payments
 */
class ShopBulkPaymentController extends Controller
{
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'order_ids'            => 'required|array|min:1',
                'order_ids.*'          => 'required|integer',
                'amount'               => 'required|numeric|min:0.01',
                // Online only — bank is mandatory for per-bank reconciliation.
                'receiving_account_id' => 'required|integer|exists:t_fin_online_receiving_accounts,id',
                'payment_date'         => 'nullable|date|before_or_equal:today',
                'reference'            => 'nullable|string|max:255',
                'notes'                => 'nullable|string|max:1000',
                'sending_bank'         => 'nullable|string|max:100',
                'stamp_date'           => 'nullable|date',
                'stamp_ref_mode'       => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $result = app(\App\Services\Payments\ShopBulkPaymentService::class)->execute($validated, $user);

            return response()->json([
                'success' => true,
                'message' => 'Recorded Rs. ' . number_format($result['total_amount'], 2) . ' across ' . $result['count'] . ' invoice(s).',
                'data'    => $result,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\App\Exceptions\ShopBulkPaymentException $e) {
            // Business-rule failure — safe to show the message verbatim.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Mobile bulk shop payment failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
