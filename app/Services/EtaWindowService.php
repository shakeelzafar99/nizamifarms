<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The delivery ETA "window" string, IDENTICAL to what the customer app shows
 * (e.g. "Today, 4:10-4:40 PM"). Used so a WhatsApp "out for delivery" message
 * carries exactly the same window the customer already sees in the app.
 *
 * The formatting mirrors CustomerAppWebhookEmitter::buildEtaWindow() /
 * CustomerAppController::etaWindowFor() and reads the SAME config keys, so the
 * output matches them today. (Recommended future cleanup: have those two call
 * this service so there's a single source of truth — deferred here to avoid
 * touching the live customer-app path in this pass.)
 */
class EtaWindowService
{
    /**
     * Format an estimated_delivery_at value into the customer-facing window,
     * or null on empty / parse failure. Floors to the rounding interval and
     * adds the band (defaults: round 10 min, band 30 min). Plain hyphen, shared
     * meridiem collapsed ("4:00-4:30 PM"), day qualifier ("Today, "/"Tomorrow, "
     * /"Jun 30, ").
     */
    public static function format($estimatedDeliveryAt): ?string
    {
        if (empty($estimatedDeliveryAt)) {
            return null;
        }

        try {
            $band = (int) config('customer_app.eta_window_band_minutes', 30);
            $band = $band > 0 ? $band : 30;

            $round = (int) config('customer_app.eta_window_round_to_minutes', 10);
            $round = $round > 0 ? $round : 10;

            $eta           = Carbon::parse($estimatedDeliveryAt)->copy()->second(0);
            $flooredMinute = intdiv($eta->minute, $round) * $round;
            $start         = $eta->copy()->minute($flooredMinute)->second(0);
            $end           = $start->copy()->addMinutes($band);

            $dayQualifier = $start->isToday() ? 'Today, '
                : ($start->isTomorrow() ? 'Tomorrow, ' : $start->format('M j') . ', ');

            $startMer = $start->format('A');
            $endMer   = $end->format('A');
            $startT   = $start->format('g:i');
            $endT     = $end->format('g:i');

            $window = $startMer === $endMer
                ? "{$startT}-{$endT} {$endMer}"
                : "{$startT} {$startMer}-{$endT} {$endMer}";

            return $dayQualifier . $window;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Window for an order id, or null when it's not available — i.e. the order
     * isn't out_for_delivery, or "Get Times" hasn't computed the ETA yet. This
     * is exactly the signal for "send the with-ETA template" vs "send the plain
     * out-for-delivery template".
     */
    public static function forOrder($orderId): ?string
    {
        if (empty($orderId)) {
            return null;
        }
        try {
            $row = DB::table('t_crm_prod_order')
                ->where('id', $orderId)
                ->first(['order_status', 'estimated_delivery_at']);

            if (!$row || $row->order_status !== 'out_for_delivery' || empty($row->estimated_delivery_at)) {
                return null;
            }
            return self::format($row->estimated_delivery_at);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
