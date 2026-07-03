<?php

namespace App\Observers;

use App\Models\CRM\OrderPaymentModel;
use App\Services\AuditLogger;

/**
 * Phase 2 L1 — audits order-payment records (2026-07-03).
 *
 * Payments are written via OrderPaymentModel::create (verified across the
 * rider / shop / qurbani paths), so this observer captures every recording and
 * every void with who + amount + bank, feeding the per-order money timeline.
 */
class OrderPaymentAuditObserver
{
    private const WATCH = ['amount', 'payment_method', 'receiving_account_id', 'status'];

    public function created(OrderPaymentModel $p): void
    {
        AuditLogger::log(
            'payment_recorded',
            'order_payment',
            $p->id,
            'Rs ' . $p->amount,
            AuditLogger::snapshot($p, ['amount', 'payment_method', 'receiving_account_id', 'status']),
            $p->order_id ? (int) $p->order_id : null,
            'Payment recorded'
        );
    }

    public function updated(OrderPaymentModel $p): void
    {
        $changes = AuditLogger::diff($p, self::WATCH);
        if ($changes === []) {
            return;
        }

        $action = (isset($changes['status']) && $p->status === 'voided')
            ? 'payment_voided'
            : 'payment_updated';

        AuditLogger::log(
            $action,
            'order_payment',
            $p->id,
            'Rs ' . $p->amount,
            $changes,
            $p->order_id ? (int) $p->order_id : null
        );
    }
}
