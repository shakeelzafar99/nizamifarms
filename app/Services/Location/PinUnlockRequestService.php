<?php

namespace App\Services\Location;

use App\Models\CRM\CustomerModel;
use Illuminate\Support\Facades\DB;

/**
 * The rider's "please unlock this pin" request, and the banners it drives.
 *
 * A rider standing at a customer's door with a LOCKED verified pin used to have
 * one option: phone a manager. Now the refusal itself raises a request, and the
 * store-mode order list and the web orders page each show a banner with an
 * Unlock button.
 *
 * ⭐ ONE query behind BOTH banners (`live()`), so the two surfaces can never
 * disagree about what is outstanding — the same rule that keeps the proof badge
 * honest across web and mobile.
 *
 * ⭐ THE ANTI-DEAD-BANNER DESIGN. A stored alert that has to be cleared by a
 * second, separate action is exactly how boards fill with notices nobody can act
 * on. Two independent mechanisms prevent it here:
 *
 *   1. Every action that resolves the lock clears the request, because they all
 *      go through CustomerModel::pinGrantGrace/pinGrantConsumed, which merge in
 *      pinUnlockRequestCleared(). Unlock, save, relock, dismiss — all of them.
 *   2. live() ALSO requires the pin to still be locked and the request to be
 *      inside its TTL. So even a request that somehow escaped step 1 stops
 *      showing the moment an unlock is granted, and dies on its own within the
 *      shift regardless.
 *
 * The second is what makes this safe: no banner can outlive the condition it
 * describes, whatever future code forgets to do.
 */
class PinUnlockRequestService
{
    /**
     * Record (or refresh) a rider's request to unlock a customer's pin.
     *
     * Called from the 423 refusal itself, so it works no matter which client the
     * rider is on — including an APK too old to know this feature exists.
     * Best-effort by contract: the caller is in the middle of returning an error
     * to a rider, and a failure to log the request must never turn that clean
     * 423 into a 500.
     *
     * @return bool  true when a request is now standing
     */
    public static function raise(CustomerModel $customer, ?int $riderId, $orderId = null): bool
    {
        try {
            // Only keep an order id that is genuinely a LIVE order. The banner
            // joins this against t_crm_prod_order, and t_crm_shopify_order's ids
            // overlap it — a staging id reaching here (a store screen posts one)
            // would caption the banner with an unrelated customer's order number.
            // One indexed lookup on a rare path buys that away entirely.
            $resolvedOrderId = null;
            if (is_numeric($orderId)) {
                $exists = DB::table('t_crm_prod_order')->where('id', (int) $orderId)->exists();
                $resolvedOrderId = $exists ? (int) $orderId : null;
            }

            $customer->forceFill([
                'verified_pin_unlock_requested_at'     => now(),
                'verified_pin_unlock_requested_by'     => $riderId,
                'verified_pin_unlock_request_order_id' => $resolvedOrderId,
            ])->save();

            \Log::info('Rider requested a verified-pin unlock', [
                'customer_id' => $customer->id,
                'rider_id'    => $riderId,
                'order_id'    => $resolvedOrderId,
            ]);

            return true;
        } catch (\Throwable $e) {
            \Log::warning('Could not record a pin-unlock request (non-fatal)', [
                'customer_id' => $customer->id ?? null,
                'error'       => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The open requests, newest first — the payload both banners render.
     *
     * Never throws: a banner is decoration on screens that must keep working, so
     * any failure degrades to "no banner" rather than breaking the orders feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function live(int $limit = 25): array
    {
        try {
            $ttlCutoff = now()->subMinutes(CustomerModel::VERIFIED_PIN_UNLOCK_REQUEST_TTL_MINUTES);

            $rows = DB::table('t_crm_prod_customer as c')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'c.verified_pin_unlock_requested_by')
                ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'c.verified_pin_unlock_request_order_id')
                ->whereNotNull('c.verified_pin_unlock_requested_at')
                ->where('c.verified_pin_unlock_requested_at', '>', $ttlCutoff)
                // Still LOCKED: a pin exists and no unlock grant is live. This is
                // the clause that makes the banner vanish the instant a manager
                // grants the unlock, without waiting for anything to be cleared.
                ->whereNotNull('c.latitude')
                ->whereNotNull('c.longitude')
                ->where(function ($q) {
                    $q->whereNull('c.verified_pin_unlocked_until')
                      ->orWhere('c.verified_pin_unlocked_until', '<=', now());
                })
                ->orderByDesc('c.verified_pin_unlock_requested_at')
                ->limit($limit)
                ->get([
                    'c.id as customer_id',
                    'c.first_name',
                    'c.last_name',
                    'c.verified_pin_unlock_requested_at as requested_at',
                    'c.verified_pin_unlock_requested_by as rider_id',
                    'u.fullname as rider_name',
                    'o.id as order_id',
                    'o.order_number',
                ]);

            return $rows->map(fn ($r) => [
                'customer_id'   => (int) $r->customer_id,
                'customer_name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Customer',
                'rider_id'      => $r->rider_id ? (int) $r->rider_id : null,
                'rider_name'    => $r->rider_name ?: 'A rider',
                'order_id'      => $r->order_id ? (int) $r->order_id : null,
                'order_number'  => $r->order_number ?: null,
                // Plain wall-clock, never ISO/UTC — the clients format it as-is.
                'requested_at'  => $r->requested_at
                    ? \Illuminate\Support\Carbon::parse($r->requested_at)->format('Y-m-d H:i:s')
                    : null,
            ])->values()->all();
        } catch (\Throwable $e) {
            \Log::warning('pin-unlock request lookup failed (non-fatal)', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Drop a request without unlocking — the banner's ✕ ("we handled it another
     * way", or the rider asked by mistake). Leaves the pin locked.
     */
    public static function dismiss(CustomerModel $customer): void
    {
        $customer->forceFill(CustomerModel::pinUnlockRequestCleared())->save();
    }
}
