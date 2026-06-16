<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer-app server-to-server pull endpoints (Phase 2).
 *
 * These are called by the customer-app (Vercel) backend, authenticated by the
 * `customer.app` middleware (bearer token). They are NOT customer-facing — the
 * customer-app backend resolves the customer from the mobile JWT and enforces
 * order ownership before proxying to us.
 */
class CustomerAppController extends Controller
{
    /**
     * Live rider tracking for an out-for-delivery order.
     *
     * GET /api/customer-app/orders/{orderNumber}/tracking
     *
     * {orderNumber} may be the bare Shopify number ("1234") or the NF number
     * ("SH-1234"); both resolve to the same order. Mirrors the customer app's
     * LiveDelivery shape (snake_case; their backend maps to camelCase).
     *
     * Response semantics (match the app's expectations):
     *   200 { "tracking": {...} }  -> a live fix; render the map.
     *   200 { "tracking": null  }  -> no live fix yet; fall back to timeline.
     *   404                        -> unknown order.
     */
    public function tracking(Request $request, string $orderNumber)
    {
        try {
            $nfOrderNumber = $this->normalizeOrderNumber($orderNumber);

            $order = DB::table('t_crm_prod_order')
                ->where('order_number', $nfOrderNumber)
                ->select([
                    'id',
                    'order_number',
                    'order_status',
                    'customer_id',
                    'assigned_rider_user_id',
                    'delivery_priority',
                    'estimated_delivery_at',
                ])
                ->first();

            if (!$order) {
                return response()->json([
                    'success'  => false,
                    'message'  => 'Order not found.',
                    'tracking' => null,
                ], 404);
            }

            // Only meaningful while out for delivery. Anything else -> no fix.
            if (strtolower((string) $order->order_status) !== 'out_for_delivery') {
                return response()->json(['success' => true, 'tracking' => null], 200);
            }

            if (empty($order->assigned_rider_user_id)) {
                return response()->json(['success' => true, 'tracking' => null], 200);
            }

            // Latest rider GPS fix within the freshness window.
            $staleness = (int) config('customer_app.tracking_gps_staleness_minutes', 30);
            $fix = DB::table('t_ops_rider_location')
                ->where('user_id', $order->assigned_rider_user_id)
                ->where('captured_at', '>=', now()->subMinutes($staleness))
                ->orderBy('captured_at', 'desc')
                ->first();

            if (!$fix || $fix->latitude === null || $fix->longitude === null) {
                // No fresh rider position -> app shows "tracking unavailable".
                return response()->json(['success' => true, 'tracking' => null], 200);
            }

            // Customer drop point (verified pin preferred, geocoded fallback).
            $destination = $this->resolveDestination($order->customer_id);
            if (!$destination) {
                return response()->json(['success' => true, 'tracking' => null], 200);
            }

            $tracking = [
                'order_number'    => $this->stripPrefix($order->order_number),
                'nf_order_number' => $order->order_number,
                'rider' => [
                    'lat' => (float) $fix->latitude,
                    'lng' => (float) $fix->longitude,
                ],
                'destination' => [
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                ],
                'stops_away' => $this->stopsAway($order),
                'eta'        => $order->estimated_delivery_at
                    ? \Carbon\Carbon::parse($order->estimated_delivery_at)->toIso8601String()
                    : null,
                'updated_at' => $fix->captured_at
                    ? \Carbon\Carbon::parse($fix->captured_at)->toIso8601String()
                    : now()->toIso8601String(),
            ];

            return response()->json(['success' => true, 'tracking' => $tracking], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::tracking failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            return response()->json([
                'success'  => false,
                'message'  => 'Tracking lookup failed.',
                'tracking' => null,
            ], 500);
        }
    }

    /**
     * Full order snapshot (Phase 3).
     *
     * GET /api/customer-app/orders/{orderNumber}
     *
     * {orderNumber} may be a bare Shopify number ("1234" -> assumed SH-1234)
     * or any full NF number ("SH-1234", "NF-2001", "QUR26-045"). The customer
     * app calls this once on the `accepted` webhook (to swap the Shopify view
     * for the NF order) and on pull-to-refresh.
     *
     *   200 { "order": {...} }   -> found
     *   404 { "order": null  }   -> unknown order
     */
    public function orderSnapshot(Request $request, string $orderNumber)
    {
        try {
            $nfOrderNumber = $this->resolveAnyOrderNumber($orderNumber);

            $order = DB::table('t_crm_prod_order')
                ->where('order_number', $nfOrderNumber)
                ->select([
                    'id', 'order_number', 'order_status', 'order_date',
                    'currency', 'payment_method', 'estimated_delivery_at',
                    'subtotal_price', 'discount_total', 'shipping_total',
                    'total_tax', 'tip_amount', 'total_price',
                    'name', 'address_phone', 'address_line1', 'address_line2',
                    'address_city', 'address_province', 'address_postal_code',
                    'address_country',
                ])
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'order' => null], 404);
            }

            $items = DB::table('t_crm_prod_order_line_item')
                ->where('order_id', $order->id)
                ->select(['sku', 'name', 'quantity', 'unit_price', 'line_total'])
                ->get()
                ->map(fn ($i) => [
                    'sku'        => $i->sku,
                    'name'       => $i->name,
                    'quantity'   => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'line_total' => (float) $i->line_total,
                ])->values();

            $payload = [
                'order_number'    => $this->stripShopifyPrefix($order->order_number),
                'nf_order_number' => $order->order_number,
                'source'          => $this->sourceFromOrderNumber($order->order_number),
                'status'          => $order->order_status,
                'eta_window'      => $this->etaWindowFor($order),
                'placed_at'       => $order->order_date
                    ? \Carbon\Carbon::parse($order->order_date)->toIso8601String() : null,
                'currency'        => $order->currency ?: 'PKR',
                'payment_method'  => $order->payment_method,
                'totals'          => [
                    'subtotal' => (float) $order->subtotal_price,
                    'discount' => (float) $order->discount_total,
                    'shipping' => (float) $order->shipping_total,
                    'tax'      => (float) $order->total_tax,
                    'tip'      => (float) $order->tip_amount,
                    'total'    => (float) $order->total_price,
                ],
                'address'         => [
                    'name'        => $order->name,
                    'phone'       => $order->address_phone,
                    'line1'       => $order->address_line1,
                    'line2'       => $order->address_line2,
                    'city'        => $order->address_city,
                    'province'    => $order->address_province,
                    'postal_code' => $order->address_postal_code,
                    'country'     => $order->address_country,
                ],
                'items'           => $items,
            ];

            return response()->json(['success' => true, 'order' => $payload], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::orderSnapshot failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'order' => null], 500);
        }
    }

    /**
     * Order history keyed on the customer's mobile number (Phase 3).
     *
     * GET /api/customer-app/customers/{mobile}/orders?limit=20
     *
     * {mobile} may be in any format; NF normalizes it exactly the way it tags
     * customers (strip non-digits -> last 10 digits) and returns every
     * production order linked to that customer — SH-, NF- and QUR — newest
     * first. Compact rows; tap-through detail comes from orderSnapshot().
     *
     * The caller MUST pass only the authenticated user's own number — NF
     * authenticates the backend, not the end customer.
     */
    public function customerOrders(Request $request, string $mobile)
    {
        try {
            $normalized = CustomerModel::normalizePhone(urldecode($mobile))['normalized'] ?? '';

            if ($normalized === '' || $normalized === str_repeat('0', 10)) {
                return response()->json([
                    'success'       => true,
                    'matched_phone' => '',
                    'orders'        => [],
                ], 200);
            }

            $customerId = $this->resolveCustomerIdByPhone($normalized);
            if (!$customerId) {
                return response()->json([
                    'success'       => true,
                    'matched_phone' => $normalized,
                    'orders'        => [],
                ], 200);
            }

            $limit = (int) $request->query('limit', 20);
            $limit = max(1, min($limit, 50));

            $orders = DB::table('t_crm_prod_order')
                ->where('customer_id', $customerId)
                ->orderByDesc('order_date')
                ->orderByDesc('id')
                ->limit($limit)
                ->select(['id', 'order_number', 'order_status', 'order_date', 'total_price', 'currency'])
                ->get();

            // One grouped query for item counts across the page.
            $counts = [];
            if ($orders->isNotEmpty()) {
                $counts = DB::table('t_crm_prod_order_line_item')
                    ->whereIn('order_id', $orders->pluck('id')->all())
                    ->groupBy('order_id')
                    ->selectRaw('order_id, COUNT(*) as c')
                    ->pluck('c', 'order_id')
                    ->all();
            }

            $rows = $orders->map(fn ($o) => [
                'order_number'    => $this->stripShopifyPrefix($o->order_number),
                'nf_order_number' => $o->order_number,
                'source'          => $this->sourceFromOrderNumber($o->order_number),
                'status'          => $o->order_status,
                'placed_at'       => $o->order_date
                    ? \Carbon\Carbon::parse($o->order_date)->toIso8601String() : null,
                'total'           => (float) $o->total_price,
                'currency'        => $o->currency ?: 'PKR',
                'item_count'      => (int) ($counts[$o->id] ?? 0),
            ])->values();

            return response()->json([
                'success'       => true,
                'matched_phone' => $normalized,
                'orders'        => $rows,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::customerOrders failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success'       => false,
                'matched_phone' => '',
                'orders'        => [],
            ], 500);
        }
    }

    /**
     * Resolve the primary customer id for a normalized phone, following the
     * merged-customer chain so merged duplicates still return the surviving
     * customer's full history. Returns null when no customer matches.
     */
    private function resolveCustomerIdByPhone(string $normalizedPhone): ?int
    {
        $customer = CustomerModel::where('phone_normalized', $normalizedPhone)
            ->whereNull('merged_into_customer_id')
            ->value('id');

        if ($customer) {
            return (int) $customer;
        }

        // Only merged rows exist — follow the chain to the survivor.
        $merged = CustomerModel::where('phone_normalized', $normalizedPhone)
            ->whereNotNull('merged_into_customer_id')
            ->value('merged_into_customer_id');

        $guard = 0;
        while ($merged && $guard++ < 10) {
            $next = CustomerModel::where('id', $merged)->value('merged_into_customer_id');
            if (!$next) {
                return (int) $merged;
            }
            $merged = $next;
        }

        return $merged ? (int) $merged : null;
    }

    /** Map an NF order_number prefix to a coarse source label. */
    private function sourceFromOrderNumber(?string $nf): string
    {
        $nf = (string) $nf;
        if (str_starts_with($nf, 'SH-'))  return 'shopify';
        if (str_starts_with($nf, 'NF-'))  return 'manual';
        if (str_starts_with($nf, 'QUR'))  return 'qurbani';
        return 'other';
    }

    /** Strip only the SH- prefix (Shopify's bare number); leave NF-/QUR as-is. */
    private function stripShopifyPrefix(string $orderNumber): string
    {
        return str_starts_with($orderNumber, 'SH-') ? substr($orderNumber, 3) : $orderNumber;
    }

    /**
     * Resolve a caller-supplied order number to the stored NF order_number.
     * A value with letters is treated as a full NF number (prefix upper-cased
     * for a stable match); a bare numeric value is assumed to be a Shopify
     * order and gets the SH- prefix.
     */
    private function resolveAnyOrderNumber(string $raw): string
    {
        $value = ltrim(trim(urldecode($raw)), '#');
        if ($value === '') {
            return $value;
        }
        if (preg_match('/[A-Za-z]/', $value)) {
            return strtoupper($value);
        }
        return 'SH-' . $value;
    }

    /**
     * Coarse human ETA window for a snapshot — only when out for delivery and
     * an ETA exists. Mirrors CustomerAppWebhookEmitter's format so the snapshot
     * and the webhook agree.
     */
    private function etaWindowFor(object $order): ?string
    {
        if (strtolower((string) $order->order_status) !== 'out_for_delivery') {
            return null;
        }
        if (empty($order->estimated_delivery_at)) {
            return null;
        }
        try {
            $band  = (int) config('customer_app.eta_window_band_hours', 2);
            $band  = $band > 0 ? $band : 2;
            $start = \Carbon\Carbon::parse($order->estimated_delivery_at)->copy()->minute(0)->second(0);
            $end   = $start->copy()->addHours($band);

            $day = $start->isToday() ? 'Today, '
                 : ($start->isTomorrow() ? 'Tomorrow, ' : $start->format('M j') . ', ');

            $window = $start->format('A') === $end->format('A')
                ? $start->format('g') . '-' . $end->format('g') . ' ' . $end->format('A')
                : $start->format('g A') . '-' . $end->format('g A');

            return $day . $window;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Number of the rider's other out-for-delivery stops sequenced ahead of
     * this one. 0 means this drop is next.
     */
    private function stopsAway(object $order): int
    {
        if ($order->delivery_priority === null) {
            return 0;
        }

        return (int) DB::table('t_crm_prod_order')
            ->where('assigned_rider_user_id', $order->assigned_rider_user_id)
            ->where('order_status', 'out_for_delivery')
            ->whereNotNull('delivery_priority')
            ->where('delivery_priority', '<', $order->delivery_priority)
            ->count();
    }

    /**
     * Resolve the customer drop point: verified lat/lng first, geocoded
     * fallback. Returns null when we have no usable coordinates.
     */
    private function resolveDestination($customerId): ?array
    {
        if (empty($customerId)) {
            return null;
        }

        $customer = DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->select(['latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude'])
            ->first();

        if (!$customer) {
            return null;
        }

        $lat = $customer->latitude ?: $customer->geocoded_latitude;
        $lng = $customer->longitude ?: $customer->geocoded_longitude;

        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /**
     * Accept "1234", "SH-1234", or a URL-encoded "#1234" and return the NF
     * order_number ("SH-1234").
     */
    private function normalizeOrderNumber(string $raw): string
    {
        $value  = trim(urldecode($raw));
        $value  = ltrim($value, '#');
        $prefix = (string) config('customer_app.strip_prefix_for_payload', 'SH-');

        if ($prefix !== '' && !str_starts_with($value, $prefix)) {
            return $prefix . $value;
        }
        return $value;
    }

    /** Strip the NF prefix for the customer-facing order number. */
    private function stripPrefix(string $orderNumber): string
    {
        $prefix = (string) config('customer_app.strip_prefix_for_payload', 'SH-');
        if ($prefix !== '' && str_starts_with($orderNumber, $prefix)) {
            return substr($orderNumber, strlen($prefix));
        }
        return $orderNumber;
    }
}
