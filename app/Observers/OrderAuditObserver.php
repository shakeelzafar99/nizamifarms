<?php

namespace App\Observers;

use App\Models\CRM\OrderModel;
use App\Services\AuditLogger;

/**
 * Phase 2 L1 — audits money/ownership changes on orders (2026-07-03).
 *
 * NOT status changes — those already have a full trail in
 * t_crm_order_status_history (changed_by/changed_at), which the audit viewer
 * merges into the per-order timeline. This observer only records the fields
 * that had NO who/old→new trail before.
 */
class OrderAuditObserver
{
    /** Fields worth a "who changed what" row (order_status excluded on purpose). */
    private const WATCH = [
        'payment_method',
        'total_price',
        'customer_id',
        'assigned_rider_user_id',
        'name',
        'discount_total',
        'address_line1',
        'address_city',
        'address_phone',
    ];

    public function updated(OrderModel $order): void
    {
        $changes = AuditLogger::diff($order, self::WATCH);
        if ($changes === []) {
            return; // nothing audited changed (e.g. a status-only save)
        }

        AuditLogger::log(
            'updated',
            'order',
            $order->id,
            $order->order_number,
            $changes,
            $order->id
        );
    }

    public function deleted(OrderModel $order): void
    {
        AuditLogger::log(
            'deleted',
            'order',
            $order->id,
            $order->order_number,
            AuditLogger::snapshot($order, ['order_number', 'total_price', 'order_status', 'payment_method'], true),
            $order->id,
            'Order deleted'
        );
    }
}
