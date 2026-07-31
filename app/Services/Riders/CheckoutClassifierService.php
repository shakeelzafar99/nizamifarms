<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;

/**
 * Classifies WHERE a rider checked out (for the manager's attendance screens): at the
 * office, at a customer's delivery point, or somewhere else — plus a flag when that
 * delivery point was NOT the address's verified pin. Display-only; the pin verdict comes from
 * VerifiedPinRule (the one shared implementation), the radius from the checkout config.
 *
 * This is the attendance-screen twin of RiderDayReportService::checkoutAudit (which flags
 * the same thing on the daily-issues report). Kept separate because this labels every
 * checkout for display, while the audit only emits the flagged case.
 */
class CheckoutClassifierService
{
    private ?array $officeMemo = null;
    private ?float $radiusMemo = null;
    private ?float $officeAtMemo = null;

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

    /**
     * The "physically AT the office" radius. Shared with check-in (LocationService) via the
     * OFFICE_AT_RADIUS_M config so both surfaces agree on what "at office" means. It CAPS the
     * office's own radius_meters (default 300) — the main office's radius is 2000 m, far too loose
     * to treat as "at office" for the checkout exception. Never loosens a tighter office.
     */
    private function officeAtRadiusM(?float $locationRadius): float
    {
        if ($this->officeAtMemo === null) {
            try {
                $v = DB::table('t_fin_config')->where('config_key', 'OFFICE_AT_RADIUS_M')->value('config_value');
                $this->officeAtMemo = ($v !== null && $v !== '' && (float) $v > 0) ? (float) $v : 300.0;
            } catch (\Throwable $e) { $this->officeAtMemo = 300.0; }
        }
        $base = ($locationRadius && $locationRadius > 0) ? $locationRadius : 300.0;
        return min($base, $this->officeAtMemo);
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

        // 1) most recent delivered order that day, if the checkout is near its drop point.
        // Checked FIRST — a delivery drop can sit within the office's loose radius, so checking
        // the office first would mis-label a genuine at-door checkout near the office as "office".
        try {
            // delivery_accuracy_m only exists after the GPS-accuracy hardening SQL; select it
            // conditionally so this keeps working on a DB where that hasn't run yet.
            $accCol = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_order_status_history', 'delivery_accuracy_m')
                ? 'h.delivery_accuracy_m'
                : DB::raw('NULL as delivery_accuracy_m');
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
                         'c.latitude as ver_lat', 'c.longitude as ver_lng', $accCol)
                ->first();
        } catch (\Throwable $e) {
            $last = null;
        }
        if ($last) {
            $dDrop = $this->haversine($lat, $lng, (float) $last->pin_lat, (float) $last->pin_lng);
            if ($dDrop <= $this->checkoutRadiusM()) {
                // Same verdict class every other screen uses — a checkout flagged here can no
                // longer contradict the same delivery's row on Day Review.
                $pinAway = null; $pinDist = null;
                $verdict = VerifiedPinRule::judge(
                    $last->pin_lat, $last->pin_lng, $last->ver_lat, $last->ver_lng,
                    $last->delivery_accuracy_m ?? null
                );
                if ($verdict) {
                    $pinDist = $verdict['distance_m'];
                    $pinAway = !$verdict['at_verified'];
                }
                $cust = trim((string) $last->customer_name) ?: 'customer';
                return ['status' => 'delivery', 'label' => 'At ' . $cust,
                        'customer' => $cust, 'order_number' => $last->order_number,
                        'pin_away' => $pinAway, 'pin_distance_m' => $pinDist];
            }
        }

        // 2) office — using the tight "at office" radius (OFFICE_AT_RADIUS_M cap), not the loose
        // check-in radius, so "At office" means he actually returned to the office.
        $office = $this->office();
        if ($office && $office->latitude !== null && $office->longitude !== null) {
            $dOffice = $this->haversine($lat, $lng, (float) $office->latitude, (float) $office->longitude);
            if ($dOffice <= $this->officeAtRadiusM($office->radius_meters !== null ? (float) $office->radius_meters : null)) {
                return ['status' => 'office', 'label' => 'At office', 'customer' => null,
                        'order_number' => null, 'pin_away' => null, 'pin_distance_m' => null];
            }
        }

        return ['status' => 'elsewhere', 'label' => 'Away from office / delivery',
                'customer' => null, 'order_number' => null, 'pin_away' => null, 'pin_distance_m' => null];
    }

    /**
     * BATCHED office-checkout exceptions over a date range, for the Month tab (R9.1). Same rule as
     * classify() — delivery-first, then the tight OFFICE_AT_RADIUS_M office — applied to a whole
     * month with only 3 queries (attendance + deliveries + bike set) and pure-PHP haversine, so it
     * never fires per-day classify() calls. An exception = a NON-company-bike rider who delivered ≥1
     * order that day but whose checkout GPS is at the office (not at his last drop).
     *
     * @return array [userId => [ ['date','checkout_time','minutes_after','last_customer','last_order'], … ]]
     */
    public function officeCheckoutDays(array $userIds, string $from, string $to): array
    {
        $out = [];
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return $out;
        }
        try {
            // Attendance rows with a checkout GPS in the window.
            $atts = DB::table('t_ops_attendance')
                ->whereIn('user_id', $userIds)
                ->whereBetween('attendance_date', [$from, $to])
                ->whereNotNull('logout_time')->where('logout_time', '!=', '')
                ->whereNotNull('checkout_latitude')->whereNotNull('checkout_longitude')
                ->get(['user_id', 'attendance_date', 'logout_time', 'checkout_latitude', 'checkout_longitude']);
            if ($atts->isEmpty()) {
                return $out;
            }

            // Company-bike riders are excluded (their office checkout is normal).
            $bikeIds = [];
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'company_bike')) {
                $bikeIds = array_flip(DB::table('t_ops_rider_profile')->where('company_bike', 1)
                    ->whereIn('user_id', $userIds)->pluck('user_id')->all());
            }

            // Deliveries in the window → per (uid|date): count + the LAST drop (coords/customer/order/time).
            $delRows = DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->where('h.status_code', 'delivered')
                ->whereIn('o.assigned_rider_user_id', $userIds)
                ->whereNotNull('h.delivery_latitude')->whereNotNull('h.delivery_longitude')
                ->whereBetween('h.changed_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->orderBy('h.changed_at')
                ->get(['o.assigned_rider_user_id as uid', 'h.changed_at', 'h.delivery_latitude as plat',
                       'h.delivery_longitude as plng', 'o.order_number', 'o.name as customer']);
            $delByKey = [];
            foreach ($delRows as $d) {
                $key = $d->uid . '|' . substr((string) $d->changed_at, 0, 10);
                $delByKey[$key]['count'] = ($delByKey[$key]['count'] ?? 0) + 1;
                $delByKey[$key]['last'] = $d; // asc order → last wins
            }

            $office = $this->office();
            $officeRadius = ($office && $office->radius_meters !== null)
                ? $this->officeAtRadiusM((float) $office->radius_meters)
                : $this->officeAtRadiusM(null);
            $deliveryRadius = $this->checkoutRadiusM();

            foreach ($atts as $att) {
                $uid = (int) $att->user_id;
                if (isset($bikeIds[$uid])) { continue; }
                $date = substr((string) $att->attendance_date, 0, 10);
                $key = $uid . '|' . $date;
                if (empty($delByKey[$key]['count'])) { continue; } // no delivery that day → office normal
                if (!$office || $office->latitude === null || $office->longitude === null) { continue; }

                $lat = (float) $att->checkout_latitude; $lng = (float) $att->checkout_longitude;
                $last = $delByKey[$key]['last'];

                // Delivery-first: if the checkout is at the last drop, it's a delivery checkout, not office.
                $dDrop = $this->haversine($lat, $lng, (float) $last->plat, (float) $last->plng);
                if ($dDrop <= $deliveryRadius) { continue; }

                // Else: at the office (tight radius) → the exception.
                $dOffice = $this->haversine($lat, $lng, (float) $office->latitude, (float) $office->longitude);
                if ($dOffice > $officeRadius) { continue; } // elsewhere → not an office checkout

                $coTime = strtotime($date . ' ' . $att->logout_time);
                $lastTime = strtotime((string) $last->changed_at);
                $minsAfter = ($coTime && $lastTime && $coTime >= $lastTime) ? (int) round(($coTime - $lastTime) / 60) : null;

                $out[$uid][] = [
                    'date'          => $date,
                    'checkout_time' => substr((string) $att->logout_time, 0, 5),
                    'minutes_after' => $minsAfter,
                    'last_customer' => trim((string) $last->customer) ?: 'a customer',
                    'last_order'    => $last->order_number,
                ];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('officeCheckoutDays failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }
}
