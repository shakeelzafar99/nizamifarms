<?php

namespace App\Observers;

use App\Models\FIN\LedgerModel;
use App\Services\AuditLogger;

/**
 * Phase 2 L1 — audits ledger lifecycle (2026-07-03).
 *
 * Fills the biggest gaps from the logging audit:
 *  - CREATE: every posted invoice / payment / expense row (who + amount + accounts).
 *  - UPDATE: approval transitions (L1→L2→approved / rejected / reversed) captured
 *    as action = status_<new>, plus amount/account edits with old→new.
 *  - DELETE: a full tombstone (amount + accounts + type) — previously deletes
 *    left nothing at all.
 *
 * All ledger writers go through Eloquent save()/create() (verified), so this
 * observer sees them without touching the writers themselves.
 */
class LedgerAuditObserver
{
    private const WATCH = [
        'amount',
        'from_account_id',
        'to_account_id',
        'approval_status',
        'mode',
        'settlement_status',
    ];

    public function created(LedgerModel $l): void
    {
        AuditLogger::log(
            'created',
            'ledger',
            $l->id,
            $this->label($l),
            AuditLogger::snapshot($l, ['transaction_type', 'amount', 'from_account_id', 'to_account_id', 'approval_status', 'mode']),
            $l->order_id ? (int) $l->order_id : null,
            $l->description
        );
    }

    public function updated(LedgerModel $l): void
    {
        $changes = AuditLogger::diff($l, self::WATCH);
        if ($changes === []) {
            return;
        }

        // Surface approval transitions as their own filterable action.
        $action = isset($changes['approval_status'])
            ? 'status_' . $l->approval_status
            : 'updated';

        AuditLogger::log(
            $action,
            'ledger',
            $l->id,
            $this->label($l),
            $changes,
            $l->order_id ? (int) $l->order_id : null
        );
    }

    public function deleted(LedgerModel $l): void
    {
        AuditLogger::log(
            'deleted',
            'ledger',
            $l->id,
            $this->label($l),
            AuditLogger::snapshot($l, ['transaction_type', 'amount', 'from_account_id', 'to_account_id', 'approval_status'], true),
            $l->order_id ? (int) $l->order_id : null,
            'Ledger row deleted'
        );
    }

    private function label(LedgerModel $l): string
    {
        return '#' . $l->id . ' ' . ($l->transaction_type ?? 'txn');
    }
}
