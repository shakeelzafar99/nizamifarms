<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;

/**
 * Classifies WHERE a rider checked out (for the manager's attendance screens): at the
 * office, at a customer's delivery point, or somewhere else — plus a flag when that
 * delivery point was NOT the address's verified pin. Display-only; reuses the app-wide
 * verified-pin rule (config rider_reports.at_verified_m) and the checkout radius.
 *
 * This is the attendance-screen twin of RiderDayReportService::checkoutAudit (which flags
 * the same thing on the daily-issues report). Kept separate because this labels every
 * checkout for display, while the audit only emits the flagged case.
 */
class CheckoutClassifierService
{
    private ?array $officeMemo = null;
    private ?float $radiusMemo = null;
    private ?float $atVerifiedMemo = null;

    private function office(): ?object
    {
        if ($this->officeMemo === null) {
            try {
                $o = DB::table('t_ops_company_locations')->where('is_primary', 1)->where('is_active', 1)
                    ->select('latitude', 'longitude', 'radius_meters')->first();
                $this->officeMemo = [$o];
            } catch (\Throwable $e) {
                $this->officeMemo = [null];
            }
        }
        return $this->officeMemo[0];
    }

    private function checkoutRadiusM(): float
    {
        if ($this->radiusMemo === null) {
            try {
                $v = DB::table('t_fin_config')->where('config_key', 'CHECKOUT_DELIVERY_RADIUS_M')->value('config_value');
                $this->radiusMemo = ($v !== null && $v !== '') ? (float) $v : 150.0;
            } catch (\Throwable $e) { $this->radiusMemo = 150.0; }
        }
        return $this->radiusMemo;
    }

    private function atVerifiedM(): float
    {
        if ($this->atVerifiedMemo === null) {
            $this->atVerifiedMemo = (float) config('rider_reports.at_verified_m', 500);
        }
        return $this->atVerifiedMemo;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array|null  null when there's no checkout GPS to classify. Otherwise:
     *   ['status'=>'office'|'delivery'|'elsewhere', 'label'=>string,
     *    'customer'=>?string, 'order_number'=>?string,
     *    'pin_away'=>?bool, 'pin_distance_m'=>?int]
     */
    public function classify(int $userId, string $date, $lat, $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        $lat = (float) $lat; $lng = (float) $lng;

        // 1) office
        $office = $this->office();
        if ($office && $office->latitude !== null && $office->longitude !== null) {
            $dOffice = $this->haversine($lat, $lng, (float) $office->latitude, (float) $office->longitude);
            if ($dOffice <= (float) ($office->radius_meters ?: 300)) {
                return ['status' => 'office', 'label' => 'At office', 'customer' => null,
                        'order_number' => null, 'pin_away' => null, 'pin_distance_m' => null];
            }
        }

        // 2) most recent delivered order that day, if the checkout is near its drop point
        try {
            $last = DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('h.status_code', 'delivered')
                ->where('o.assigned_rider_user_id', $userId)
                ->whereNotNull('h.delivery_latitude')->whereNotNull('h.delivery_longitude')
                ->whereDate('h.changed_at', $date)
                ->orderByDesc('h.changed_at')
                ->select('h.delivery_latitude as pin_lat', 'h.delivery_longitude as pin_lng',
                         'o.order_number', 'o.name as customer_name',
                         'c.latitude as ver_lat', 'c.longitude as ver_lng')
                ->first();
        } catch (\Throwable $e) {
            $last = null;
        }
        if ($last) {
            $dDrop = $this->haversine($lat, $lng, (float) $last->pin_lat, (float) $last->pin_lng);
            if ($dDrop <= $this->checkoutRadiusM()) {
                $pinAway = null; $pinDist = null;
                if ($last->ver_lat !== null && $last->ver_lng !== null) {
                    $dPin = $this->haversine((float) $last->pin_lat, (float) $last->pin_lng, (float) $last->ver_lat, (float) $last->ver_lng);
                    $pinDist = (int) round($dPin);
                    $pinAway = $dPin > $this->atVerifiedM();
                }
                $cust = trim((string) $last->customer_name) ?: 'customer';
                return ['status' => 'delivery', 'label' => 'At ' . $cust,
                        'customer' => $cust, 'order_number' => $last->order_number,
                        'pin_away' => $pinAway, 'pin_distance_m' => $pinDist];
            }
        }

        return ['status' => 'elsewhere', 'label' => 'Away from office / delivery',
                'customer' => null, 'order_number' => null, 'pin_away' => null, 'pin_distance_m' => null];
    }
}
