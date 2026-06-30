<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surfaces a customer's inbound quick-reply BUTTON taps ("Confirm Wednesday",
 * "Split delivery", "Cancel order") next to their order, so store staff /
 * approvers can see the customer's choice.
 *
 * Reads from the WhatsApp tables only (t_wa_messages / t_wa_conversations) —
 * the button taps are already captured there as type 'button'/'interactive'
 * with the label in `content` and the id in `metadata`. Matching is by
 * conversation.customer_id (conversations auto-link to the customer by phone).
 * Read-only and fully guarded — never throws.
 */
class OrderReplyService
{
    /** WhatsApp message types that represent a quick-reply button tap. */
    const REPLY_TYPES = ['button', 'interactive'];

    /**
     * Latest button reply per customer, for batch-enriching an order list with
     * an "options received" marker. One query for many customers.
     *
     * @param int[] $customerIds
     * @param int|null $sinceDays  look-back window (default 30); null = no bound.
     * @return array<int, array{text:string, button_id:?string, at:?string}>
     *         keyed by customer_id; only customers WITH a reply appear.
     */
    public static function latestReplyForCustomers(array $customerIds, ?int $sinceDays = 30): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (empty($customerIds)
            || !Schema::hasTable('t_wa_messages')
            || !Schema::hasTable('t_wa_conversations')) {
            return [];
        }

        try {
            $q = DB::table('t_wa_messages as m')
                ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->whereIn('c.customer_id', $customerIds)
                ->where('m.direction', 'inbound')
                ->whereIn('m.type', self::REPLY_TYPES);
            if ($sinceDays) {
                $q->where('m.created_at', '>=', now()->subDays($sinceDays));
            }
            $rows = $q->orderByDesc('m.created_at')
                ->get(['c.customer_id', 'm.content', 'm.metadata', 'm.created_at']);

            $out = [];
            foreach ($rows as $r) {
                $cid = (int) $r->customer_id;
                if (isset($out[$cid])) {
                    continue; // rows are newest-first → first seen is the latest.
                }
                $out[$cid] = [
                    'text'      => (string) $r->content,
                    'button_id' => self::buttonId($r->metadata),
                    'at'        => $r->created_at,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Recent button replies for ONE customer (newest first) — for the detail /
     * approve view where staff need to read exactly what the customer chose.
     *
     * @return array<int, array{text:string, button_id:?string, at:?string}>
     */
    public static function repliesForCustomer(int $customerId, ?int $sinceDays = 60, int $limit = 10): array
    {
        if (!$customerId
            || !Schema::hasTable('t_wa_messages')
            || !Schema::hasTable('t_wa_conversations')) {
            return [];
        }

        try {
            $q = DB::table('t_wa_messages as m')
                ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('c.customer_id', $customerId)
                ->where('m.direction', 'inbound')
                ->whereIn('m.type', self::REPLY_TYPES);
            if ($sinceDays) {
                $q->where('m.created_at', '>=', now()->subDays($sinceDays));
            }
            $rows = $q->orderByDesc('m.created_at')
                ->limit(max(1, $limit))
                ->get(['m.content', 'm.metadata', 'm.created_at']);

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'text'      => (string) $r->content,
                    'button_id' => self::buttonId($r->metadata),
                    'at'        => $r->created_at,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Pull the button id/payload out of a t_wa_messages.metadata JSON blob. */
    protected static function buttonId($metadata): ?string
    {
        if (empty($metadata)) {
            return null;
        }
        $m = is_array($metadata) ? $metadata : json_decode((string) $metadata, true);
        if (!is_array($m)) {
            return null;
        }
        $id = $m['button_id'] ?? ($m['button_payload'] ?? null);
        return $id !== null ? (string) $id : null;
    }
}
