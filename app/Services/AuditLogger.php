<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 L1 — the audit-trail writer (2026-07-03).
 *
 * ONE small indexed INSERT per audited business action, into t_sys_audit_log.
 * Called from Eloquent observers (Order / Ledger / OrderPayment) and, later,
 * from explicit choke points (approvals, deletes, config, users).
 *
 * HARD GUARANTEES:
 *  - Never throws into the caller. A logging failure (e.g. the table not yet
 *    created on prod after a PHP-first upload) is swallowed — the business
 *    action always completes.
 *  - Zero cost on read/poll paths (it is only called from write events).
 *  - No extra queries beyond the single insert (the table-exists check is
 *    cached per request; source detection reads the current request only).
 */
class AuditLogger
{
    /** Per-request cache of the table-exists check (null = not checked yet). */
    private static ?bool $tableExists = null;

    /**
     * Record one audited action. All args past $entityId are optional.
     *
     * @param string     $action         e.g. 'updated', 'status_approved', 'payment_recorded'
     * @param string     $entityType     e.g. 'order', 'ledger', 'order_payment'
     * @param int|null   $entityId
     * @param string|null$entityLabel    human tag (order_number, "#12 invoice", "Rs 1180")
     * @param array|null $changes        {field: {old, new}} — from diff()/snapshot()
     * @param int|null   $relatedOrderId prod order id this touched (feeds the order timeline)
     * @param string|null$note
     */
    public static function log(
        string $action,
        string $entityType,
        $entityId = null,
        ?string $entityLabel = null,
        ?array $changes = null,
        ?int $relatedOrderId = null,
        ?string $note = null
    ): void {
        try {
            if (self::$tableExists === null) {
                self::$tableExists = Schema::hasTable('t_sys_audit_log');
            }
            if (!self::$tableExists) {
                return;
            }

            DB::table('t_sys_audit_log')->insert([
                'at'               => now(),
                'user_id'          => self::userId(),
                'source'           => self::detectSource(),
                'action'           => substr($action, 0, 40),
                'entity_type'      => substr($entityType, 0, 40),
                'entity_id'        => $entityId,
                'entity_label'     => $entityLabel !== null ? substr($entityLabel, 0, 80) : null,
                'related_order_id' => $relatedOrderId,
                'changes'          => ($changes !== null && $changes !== []) ? json_encode($changes) : null,
                'note'             => $note !== null ? substr($note, 0, 255) : null,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must NEVER break a business action.
            try {
                Log::warning('AuditLogger insert failed', ['error' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
                // give up silently
            }
        }
    }

    /**
     * Build a {field:{old,new}} map for the whitelisted fields that ACTUALLY
     * changed in the just-saved model. Uses in-memory Eloquent state only
     * (getChanges/getOriginal) — no queries. Call from an `updated` observer.
     */
    public static function diff($model, array $whitelist): array
    {
        $out = [];
        foreach ($model->getChanges() as $field => $new) {
            if (!in_array($field, $whitelist, true)) {
                continue;
            }
            $old = $model->getOriginal($field);
            // Normalise loose numeric/string equality (e.g. "10.00" vs 10) to
            // avoid logging no-op writes.
            if ((string) $old === (string) $new) {
                continue;
            }
            $out[$field] = ['old' => $old, 'new' => $new];
        }
        return $out;
    }

    /**
     * Snapshot the given fields as a {field:{old,new}} map for create/delete
     * events. $removing=true → values go under `old` (tombstone); else `new`.
     */
    public static function snapshot($model, array $fields, bool $removing = false): array
    {
        $out = [];
        foreach ($fields as $field) {
            $val = $model->getAttribute($field);
            $out[$field] = $removing ? ['old' => $val, 'new' => null] : ['old' => null, 'new' => $val];
        }
        return $out;
    }

    private static function userId(): ?int
    {
        try {
            return auth()->id();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function detectSource(): string
    {
        try {
            if (app()->runningInConsole()) {
                return 'system';
            }
            $req = request();
            if (!$req) {
                return 'system';
            }
            if ($req->is('api/customer-app/*')) {
                return 'customer_app';
            }
            if ($req->is('api/*')) {
                return 'mobile';
            }
            return 'web';
        } catch (\Throwable $e) {
            return 'system';
        }
    }
}
