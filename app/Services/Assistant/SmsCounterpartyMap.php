<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;

/**
 * The memory behind "next time I'll recognize this account".
 *
 * t_ai_counterparty_map rows say: this counterparty identity (a normalized
 * account fragment like AKBL*9400 / MBL*4602 / PK56UNIL01090 / MERCHANT:JAMIL
 * SWEETS..., and/or a name) belongs to a vendor / customer / expense category /
 * one of OUR OWN accounts (entity_type 'account' — a rider or staff cash float,
 * NF food, another till: money that MOVED but is still ours), or should be
 * ignored (personal debit-card merchants).
 *
 * CONFIDENCE RULES (owner-approved Jul-20):
 *   • account_key hit  → high confidence, may drive AUTO actions
 *     (auto customer-credit matching, auto-ignore of remembered merchants).
 *   • name-only hit    → SUGGESTION only, shown pre-filled but never acted on
 *     automatically — names collide (HBL credits carry no account at all).
 */
class SmsCounterpartyMap
{
    /** The active rule for an account key, or null. */
    public function byAccount(?string $accountKey): ?object
    {
        if (!$accountKey) {
            return null;
        }
        return DB::table('t_ai_counterparty_map')
            ->where('account_key', $accountKey)
            ->where('is_active', 1)
            ->first();
    }

    /**
     * A name-based rule — returned ONLY when exactly one active rule matches,
     * so an ambiguous name can never suggest the wrong person.
     */
    public function byName(?string $name): ?object
    {
        $key = self::normalizeName($name);
        if ($key === null) {
            return null;
        }
        $rows = DB::table('t_ai_counterparty_map')
            ->where('name_key', $key)
            ->where('is_active', 1)
            ->limit(2)
            ->get();
        return $rows->count() === 1 ? $rows->first() : null;
    }

    /** Best rule for an SMS row: account first (strong), then unique name. */
    public function forSms(object $sms): ?object
    {
        return $this->byAccount($sms->counterparty_account ?? null)
            ?? $this->byName($sms->counterparty ?? null);
    }

    /**
     * Save (or overwrite) a rule. Account-keyed rules upsert on account_key —
     * re-teaching an account moves it to the new entity in one step.
     * Name-only rules (no account) insert unless the identical rule exists.
     */
    public function save(?string $accountKey, ?string $name, string $entityType, ?int $entityId, ?string $entityLabel, ?int $userId): ?int
    {
        $nameKey = self::normalizeName($name);
        if (!$accountKey && !$nameKey) {
            return null; // nothing to key on
        }

        if ($accountKey) {
            $existing = DB::table('t_ai_counterparty_map')->where('account_key', $accountKey)->first();
            if ($existing) {
                DB::table('t_ai_counterparty_map')->where('id', $existing->id)->update([
                    'name_key'     => $nameKey ?: $existing->name_key,
                    'entity_type'  => $entityType,
                    'entity_id'    => $entityId,
                    'entity_label' => $entityLabel,
                    'is_active'    => 1,
                    'updated_at'   => now(),
                ]);
                return (int) $existing->id;
            }
        } else {
            $dupe = DB::table('t_ai_counterparty_map')
                ->whereNull('account_key')
                ->where('name_key', $nameKey)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->first();
            if ($dupe) {
                if (!$dupe->is_active) {
                    DB::table('t_ai_counterparty_map')->where('id', $dupe->id)
                        ->update(['is_active' => 1, 'updated_at' => now()]);
                }
                return (int) $dupe->id;
            }
        }

        return (int) DB::table('t_ai_counterparty_map')->insertGetId([
            'account_key'  => $accountKey,
            'name_key'     => $nameKey,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'entity_label' => $entityLabel,
            'is_active'    => 1,
            'created_by'   => $userId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Record that a rule just recognized an SMS (audit + usefulness). */
    public function bump(int $id): void
    {
        DB::table('t_ai_counterparty_map')->where('id', $id)->update([
            'hit_count'    => DB::raw('hit_count + 1'),
            'last_seen_at' => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Deactivate a rule ("stop auto-ignoring this merchant"). */
    public function deactivate(int $id): void
    {
        DB::table('t_ai_counterparty_map')->where('id', $id)
            ->update(['is_active' => 0, 'updated_at' => now()]);
    }

    /** Human display name of the entity a rule points to. */
    public function entityName(object $rule): string
    {
        if ($rule->entity_type === 'customer' && $rule->entity_id) {
            $c = DB::table('t_crm_prod_customer')->where('id', $rule->entity_id)->first(['first_name', 'last_name']);
            return $c ? trim($c->first_name . ' ' . ($c->last_name ?? '')) : 'customer #' . $rule->entity_id;
        }
        if ($rule->entity_type === 'vendor' && $rule->entity_id) {
            return (string) (DB::table('t_fin_vendors')->where('id', $rule->entity_id)->value('vendor_name') ?: 'vendor #' . $rule->entity_id);
        }
        // 'account' — money moved to one of OUR OWN accounts (a rider/staff cash
        // float, NF food, another till). Not a vendor and not an expense: the
        // rupees are still ours, they just sit somewhere else.
        if ($rule->entity_type === 'account' && $rule->entity_id) {
            return (string) (DB::table('t_fin_accounts')->where('id', $rule->entity_id)->value('account_name') ?: 'account #' . $rule->entity_id);
        }
        return (string) ($rule->entity_label ?: $rule->entity_type);
    }

    /** Uppercase, collapsed, punctuation-light name key. */
    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $key = mb_strtoupper(trim($name));
        $key = preg_replace('/[^A-Z ]/', ' ', $key);
        $key = trim(preg_replace('/\s+/', ' ', $key));
        return $key !== '' && mb_strlen($key) >= 3 ? mb_substr($key, 0, 120) : null;
    }
}
