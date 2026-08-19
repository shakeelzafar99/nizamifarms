<?php

namespace App\Services\Riders;

use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Scan help" — a rider asks a manager for permission to deliver an order with FEWER packets
 * than the store set, because he cannot scan them all (missing box, unreadable label).
 *
 * Born from NF-19218 (Aug-17 2026): delivered 1-of-2 while "Require scan" was ON, because the
 * rider's scan modal let a stale "1/1" label lower its own target and the backend only logged the
 * mismatch. The count is now enforced in RiderController::markOrderDelivered, and this class is
 * the ONLY sanctioned way past it.
 *
 * Design rules that keep it loophole-free:
 *   - ONE approval authority (canApprove) shared by the web banner and store mode, so the two
 *     surfaces can never drift apart.
 *   - An approval is bound to the ORDER, the RIDER who asked, and the EXPECTED COUNT at the time
 *     of asking. Change any of those and it stops being valid.
 *   - SINGLE USE: consume() stamps scan_bypass_used_at. A second delivery attempt must ask again.
 *   - Every write is guarded so the app still behaves correctly BEFORE the SQL migration runs
 *     (add_scan_help_bypass_aug17_2026.sql). Missing columns read as null = "no approval exists",
 *     which fails CLOSED (delivery blocked) rather than open.
 */
class ScanHelpService
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED   = 'denied';

    /**
     * The single approval authority. Mobile permission first — that is THE gate, grantable per role
     * from the Roles screen (the migration seeds Management, Taimur and supervisor 2 = Farooq, per
     * the owner's ruling: "admins and also Farooq, keep it in mobile permissions"). The role
     * fallback exists only so the owner-level roles can never be locked out of answering a stranded
     * rider (e.g. before the permission SQL has run).
     *
     * ⚠ There is NO role literally named 'admin' in t_sys_role — the real admin roles are
     *   'Management' and 'Taimur'. An ['admin','taimur'] check (the pattern
     *   saveDeliveryScanSettings uses) silently matches ONLY Taimur.
     */
    public function canApprove($user): bool
    {
        if (!$user) return false;
        try {
            if (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission('approve_scan_bypass')) {
                return true;
            }
        } catch (\Throwable $e) {
            // permission tables unavailable — fall through to the role check
        }
        try {
            return $user->roles()
                ->whereRaw('LOWER(urole_name) IN (?, ?)', ['management', 'taimur'])
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Rider asks for help. Idempotent-ish: re-asking while one is already pending just refreshes
     * the reason/count rather than creating a second request (he may realise he has 2 not 1).
     */
    public function request(OrderModel $order, int $riderId, string $reason, ?int $packetsInHand): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['ok' => false, 'message' => 'Please type a short reason.'];
        }

        $expected = (int) ($order->expected_packets ?: 0);
        // No count to fall short OF. The enforcement only runs when the store set one, so an
        // approval here would be a record of nothing and would never be consulted.
        if ($expected <= 0) {
            return ['ok' => false, 'message' => 'The store has not set a packet count for this order.'];
        }
        // Guard the pointless request: nothing to approve if he has as many as the store set.
        if ($packetsInHand !== null && $packetsInHand >= $expected) {
            return ['ok' => false, 'message' => 'You have all ' . $expected . ' packets — scan them instead.'];
        }

        // An already-APPROVED, unused request must not be silently downgraded back to pending by a
        // duplicate tap; hand the approval straight back so the rider can proceed.
        if ($this->approvalFor($order, $riderId) !== null) {
            return ['ok' => true, 'status' => self::STATUS_APPROVED, 'message' => 'Already approved — you can deliver now.'];
        }

        try {
            $order->scan_bypass_status       = self::STATUS_PENDING;
            $order->scan_bypass_reason       = mb_substr($reason, 0, 250);
            $order->scan_bypass_packets      = $packetsInHand;
            $order->scan_bypass_expected     = $expected ?: null;
            $order->scan_bypass_requested_at = now();
            $order->scan_bypass_requested_by = $riderId;
            $order->scan_bypass_decided_at   = null;
            $order->scan_bypass_decided_by   = null;
            $order->scan_bypass_used_at      = null;
            $order->save();
        } catch (\Throwable $e) {
            Log::error('Scan-help request could not be saved', [
                'order_id' => $order->id,
                'rider_id' => $riderId,
                'error'    => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'Could not send the request — please call the store.'];
        }

        Log::info('Scan-help requested', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'rider_id'     => $riderId,
            'expected'     => $expected,
            'in_hand'      => $packetsInHand,
            'reason'       => $reason,
        ]);

        return ['ok' => true, 'status' => self::STATUS_PENDING, 'message' => 'Sent — waiting for a manager.'];
    }

    /**
     * Manager decides. Only a PENDING request can be decided, so an Approve tap on a stale banner
     * (already decided elsewhere, or cleared by a packet-count change) cannot resurrect anything.
     */
    public function decide(int $orderId, string $decision, $actor): array
    {
        if (!$this->canApprove($actor)) {
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'You cannot approve delivery scan bypasses.'];
        }
        $decision = $decision === self::STATUS_APPROVED ? self::STATUS_APPROVED : self::STATUS_DENIED;

        $order = OrderModel::find($orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Order not found'];
        }
        if ((string) $order->scan_bypass_status !== self::STATUS_PENDING) {
            return ['ok' => false, 'code' => 'not_pending', 'message' => 'This request is no longer pending.'];
        }
        // The store may have corrected the packet count between the ask and the decision.
        $expectedNow = (int) ($order->expected_packets ?: 0);
        if ((int) ($order->scan_bypass_expected ?: 0) !== $expectedNow) {
            $this->clear($order, 'packet count changed before the decision');
            return ['ok' => false, 'code' => 'stale', 'message' => 'The packet count changed — ask the rider to request again.'];
        }

        try {
            $order->scan_bypass_status     = $decision;
            $order->scan_bypass_decided_at = now();
            $order->scan_bypass_decided_by = $actor->id;
            $order->save();
        } catch (\Throwable $e) {
            Log::error('Scan-help decision could not be saved', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return ['ok' => false, 'code' => 'save_failed', 'message' => 'Could not save the decision.'];
        }

        Log::info('Scan-help decided', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'decision'     => $decision,
            'decided_by'   => $actor->id,
            'expected'     => $expectedNow,
            'in_hand'      => $order->scan_bypass_packets,
        ]);

        return ['ok' => true, 'status' => $decision];
    }

    /**
     * The live approval for THIS rider on THIS order, or null. Every condition below is a lock
     * that the incident taught us to add — see the class docblock.
     */
    public function approvalFor(OrderModel $order, int $riderId): ?array
    {
        if ((string) ($order->scan_bypass_status ?? '') !== self::STATUS_APPROVED) return null;
        if (!empty($order->scan_bypass_used_at)) return null;                       // single use
        if ((int) ($order->scan_bypass_requested_by ?: 0) !== $riderId) return null; // reassigned rider
        // Approved for 1-of-2; if the store has since said 3, that consent no longer covers it.
        if ((int) ($order->scan_bypass_expected ?: 0) !== (int) ($order->expected_packets ?: 0)) return null;

        return [
            'reason'      => (string) ($order->scan_bypass_reason ?? ''),
            'packets'     => $order->scan_bypass_packets !== null ? (int) $order->scan_bypass_packets : null,
            'expected'    => (int) ($order->expected_packets ?: 0),
            'decided_by'  => (int) ($order->scan_bypass_decided_by ?: 0),
        ];
    }

    /**
     * Burn the approval. Called from inside the delivery flow AFTER the shortfall has been allowed.
     * Never throws — a delivery must not fail because the audit stamp could not be written, but it
     * is logged loudly because an un-burned approval is a reusable one.
     */
    public function consume(OrderModel $order): void
    {
        try {
            $order->scan_bypass_used_at = now();
            $order->save();
        } catch (\Throwable $e) {
            Log::error('Scan-help approval could not be marked used — it may be reusable', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate any request/approval on the order. Called when the store changes the packet count:
     * the whole point of an approval is "this many, this order, this rider" and the count just moved.
     */
    public function clear(OrderModel $order, string $why = ''): void
    {
        if (empty($order->scan_bypass_status) && empty($order->scan_bypass_requested_at)) return;
        try {
            $order->scan_bypass_status       = null;
            $order->scan_bypass_reason       = null;
            $order->scan_bypass_packets      = null;
            $order->scan_bypass_expected     = null;
            $order->scan_bypass_requested_at = null;
            $order->scan_bypass_requested_by = null;
            $order->scan_bypass_decided_at   = null;
            $order->scan_bypass_decided_by   = null;
            $order->scan_bypass_used_at      = null;
            $order->save();
            Log::info('Scan-help request cleared', ['order_id' => $order->id, 'why' => $why]);
        } catch (\Throwable $e) {
            // Columns not migrated yet, or a write race — nothing to clear that could be honoured.
        }
    }

    /**
     * Pending requests for the manager banners (web orders page + store mode). Newest first.
     * Returns [] on any error, including "the migration has not run yet" — a banner is an
     * awareness feature and must never be able to break the page that hosts it.
     */
    public function pending(int $limit = 25): array
    {
        try {
            $rows = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.scan_bypass_requested_by')
                ->where('o.scan_bypass_status', self::STATUS_PENDING)
                ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                ->orderByDesc('o.scan_bypass_requested_at')
                ->limit($limit)
                ->get([
                    'o.id',
                    'o.order_number',
                    'o.expected_packets',
                    'o.scan_bypass_packets',
                    'o.scan_bypass_reason',
                    'o.scan_bypass_requested_at',
                    'o.address_first_name',
                    'o.address_last_name',
                    'u.fullname as rider_name',
                ]);

            return $rows->map(function ($r) {
                $customer = trim(($r->address_first_name ?? '') . ' ' . ($r->address_last_name ?? ''));
                return [
                    'order_id'      => (int) $r->id,
                    'order_number'  => (string) $r->order_number,
                    'customer_name' => $customer !== '' ? $customer : null,
                    'rider_name'    => $r->rider_name ?: 'Rider',
                    'expected'      => (int) ($r->expected_packets ?: 0),
                    'in_hand'       => $r->scan_bypass_packets !== null ? (int) $r->scan_bypass_packets : null,
                    'reason'        => (string) ($r->scan_bypass_reason ?? ''),
                    'requested_at'  => $r->scan_bypass_requested_at,
                ];
            })->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * What the rider's phone polls while it waits. Mirrors approvalFor()'s locks so the phone is
     * never told "approved" for something the delivery endpoint would then reject.
     */
    public function statusFor(OrderModel $order, int $riderId): array
    {
        $status = (string) ($order->scan_bypass_status ?? '');
        if ($status === '') {
            return ['status' => 'none'];
        }
        if ($status === self::STATUS_APPROVED) {
            return $this->approvalFor($order, $riderId) !== null
                ? ['status' => self::STATUS_APPROVED, 'packets' => $order->scan_bypass_packets !== null ? (int) $order->scan_bypass_packets : null]
                : ['status' => 'none']; // approved, but not for him / not any more
        }
        if ($status === self::STATUS_PENDING && (int) ($order->scan_bypass_requested_by ?: 0) !== $riderId) {
            return ['status' => 'none'];
        }
        return ['status' => $status];
    }
}
