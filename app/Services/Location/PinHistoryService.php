<?php

namespace App\Services\Location;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aug-2026 — "who set this customer's pin, and how?"
 *
 * ONE authority for reading the pin trail out of t_sys_audit_log and turning it
 * into the words staff see. Web (order modal, customers page) and mobile both
 * call this, so a pin's story reads identically everywhere — the alternative is
 * the labels drifting apart the first time either side is edited.
 *
 * READ-ONLY. It never writes; the writers are AuditLogger's two pin helpers.
 *
 * Two questions it answers:
 *   forCustomer()  — the full timeline for the history popover.
 *   pendingReplies() — "is a customer-sent pin sitting unused RIGHT NOW?", which
 *                      is the amber dot on the orders list. A refusal counts only
 *                      while it is still the LATEST pin event: once someone saves
 *                      a pin afterwards the question is settled and the dot goes.
 */
class PinHistoryService
{
    /** Actions that make up a pin's story. */
    public const ACTIONS = ['pin_updated', 'pin_reply_ignored'];

    /**
     * Refusal reasons that ask a human to do something (the amber flag).
     *
     *   existing_pin_kept — nobody re-requested, so we kept the old pin. Someone
     *                       should look at whether the new one is better.
     *   reply_too_far     — a re-requested reply that jumped too far to apply
     *                       blindly; a human decides if they really moved.
     *
     * Deliberately NOT here: `staff_pin_newer` (the team already saved a fresher
     * pin, so there is nothing to chase), `qurbani_flow` (the reviewer drawer
     * owns it) and `no_request_pending` (unprompted). They stay in the history
     * as context, without raising a flag nobody needs to act on.
     */
    public const REASONS_NEEDS_ACTION = ['existing_pin_kept', 'reply_too_far'];

    /**
     * How long an unused customer pin keeps raising the amber flag. It stays in
     * the history forever; it just stops shouting. A flag that never clears is
     * one staff learn to look past, which would cost us the next Aug-2.
     */
    public const FLAG_WINDOW_DAYS = 30;

    /** Per-request cache of the table check (the SQL may not be run yet). */
    private static ?bool $tableExists = null;

    public function available(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable('t_sys_audit_log');
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }
        return self::$tableExists;
    }

    // ------------------------------------------------------------------
    // TIMELINE
    // ------------------------------------------------------------------

    /**
     * The pin trail for one customer, newest first, already described in plain
     * English. Returns [] when the audit table isn't there yet (PHP-first deploy)
     * so every caller degrades to "no history" instead of erroring.
     *
     * @return array<int,array>
     */
    public function forCustomer(int $customerId, int $limit = 15): array
    {
        if (!$this->available() || $customerId <= 0) {
            return [];
        }

        try {
            $rows = DB::table('t_sys_audit_log as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->where('a.entity_type', 'customer')
                ->where('a.entity_id', $customerId)
                ->whereIn('a.action', self::ACTIONS)
                ->orderByDesc('a.id')
                ->limit(max(1, $limit))
                ->get(['a.id', 'a.at', 'a.user_id', 'a.source', 'a.action', 'a.changes', 'a.note',
                       DB::raw("COALESCE(u.fullname,'') as user_name")]);
        } catch (\Throwable $e) {
            return [];
        }

        return $rows->map(fn ($r) => $this->describe($r))->all();
    }

    // ------------------------------------------------------------------
    // "A CUSTOMER PIN IS WAITING" FLAG
    // ------------------------------------------------------------------

    /**
     * For each customer id, the unresolved ignored-reply (or null).
     *
     * ⭐ Only the LATEST pin event counts. Haleema's Aug-2 refusal is real history
     * forever, but Farooq saved the right pin on Aug-3 — so she must NOT show as
     * needing attention today. Anything else would train staff to ignore the dot.
     *
     * @param  array<int> $customerIds
     * @return array<int,array>  keyed by customer_id, only those needing action
     */
    public function pendingReplies(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (!$this->available() || empty($customerIds)) {
            return [];
        }

        try {
            $q = DB::table('t_sys_audit_log')
                ->where('entity_type', 'customer')
                ->whereIn('entity_id', $customerIds)
                ->whereIn('action', self::ACTIONS)
                ->orderByDesc('id');

            // Only the newest row per customer decides this, so for the common
            // single-customer call (the order detail) read exactly one row rather
            // than that customer's whole pin history.
            if (count($customerIds) === 1) {
                $q->limit(1);
            }

            $rows = $q->get(['id', 'at', 'entity_id', 'action', 'changes', 'note', 'source', 'user_id']);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $cid = (int) $r->entity_id;
            if (isset($seen[$cid])) {
                continue; // already have this customer's newest pin event
            }
            $seen[$cid] = true;

            if ($r->action !== 'pin_reply_ignored') {
                continue; // newest event is a save — nothing pending
            }
            $changes = $this->decode($r->changes);
            if (!in_array((string) $this->changeValue($changes, 'reason'), self::REASONS_NEEDS_ACTION, true)) {
                continue; // staff-newer / qurbani / unprompted — informational only
            }

            // Age it out. Applied HERE and not in the query on purpose: the query
            // must still see the true newest row, otherwise an old refusal would
            // drop out and an even older one could be mistaken for current.
            try {
                if ($r->at && Carbon::parse($r->at)->lt(now()->subDays(self::FLAG_WINDOW_DAYS))) {
                    continue;
                }
            } catch (\Throwable $e) {
                // unparseable timestamp — keep the flag rather than hide it
            }

            $described = $this->describe($r);
            $out[$cid] = [
                'customer_id' => $cid,
                'at'          => $described['at'],
                'at_human'    => $described['at_human'],
                'away_text'   => $described['away_text'],
                'offered'     => $described['offered'],
                'headline'    => $described['headline'],
            ];
        }

        return $out;
    }

    /** Single-customer convenience wrapper around pendingReplies(). */
    public function pendingReply(int $customerId): ?array
    {
        return $this->pendingReplies([$customerId])[$customerId] ?? null;
    }

    /**
     * EVERY customer currently sitting on an unused pin, without being told who
     * to look at. Two cheap queries: recent refusals inside the flag window, then
     * the normal "is it still the latest event?" confirmation on just those ids.
     *
     * This is what lets the orders list badge itself without the (three separate)
     * order-list payloads having to carry the flag — and it stays small on its
     * own, because the window ages rows out and resolved ones drop away.
     *
     * @return array<int,array> keyed by customer_id
     */
    public function allPending(int $scan = 200): array
    {
        if (!$this->available()) {
            return [];
        }

        try {
            $ids = DB::table('t_sys_audit_log')
                ->where('entity_type', 'customer')
                ->where('action', 'pin_reply_ignored')
                ->where('at', '>=', now()->subDays(self::FLAG_WINDOW_DAYS))
                ->orderByDesc('id')
                ->limit(max(1, $scan))
                ->pluck('entity_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }

        return empty($ids) ? [] : $this->pendingReplies($ids);
    }

    // ------------------------------------------------------------------
    // PER-MESSAGE VERDICTS  (what the chat bubble says)
    // ------------------------------------------------------------------

    /**
     * Aug-2026 — the outcome of each location reply, for the chat window.
     *
     * ⭐ The chat must never guess. Before the re-request rule existed the bubble
     * decided its own message from "does this customer have a pin?", which is now
     * wrong in the most important case: a re-requested reply DOES overwrite, so
     * that logic would print "not saved automatically" directly underneath a pin
     * it had just saved. These are the server's real verdicts; the client matches
     * them to messages by timestamp and prints nothing when it has no match.
     *
     * WhatsApp-sourced events only — a staff save happens minutes later and would
     * mis-attach to the bubble.
     *
     * @return array<int,array>  newest first
     */
    public function replyVerdicts(int $customerId, int $limit = 40): array
    {
        if (!$this->available() || $customerId <= 0) {
            return [];
        }

        try {
            $rows = DB::table('t_sys_audit_log')
                ->where('entity_type', 'customer')
                ->where('entity_id', $customerId)
                ->where('source', \App\Services\AuditLogger::SOURCE_WHATSAPP)
                ->whereIn('action', self::ACTIONS)
                ->orderByDesc('id')
                ->limit(max(1, $limit))
                ->get(['id', 'at', 'user_id', 'source', 'action', 'changes', 'note']);
        } catch (\Throwable $e) {
            return [];
        }

        return $rows->map(function ($r) {
            $d = $this->describe($r);
            $saved = $d['kind'] === 'saved';
            return [
                'at'           => $d['at'],
                'saved'        => $saved,
                'needs_action' => (bool) $d['needs_action'],
                'tone'         => $saved ? 'green' : $d['tone'],
                'headline'     => $saved
                    ? ($d['old'] ? 'Saved — this new pin replaced the old one' : 'Saved as the verified location')
                    : $d['headline'],
                'detail'       => $saved ? ($d['moved_text'] ?: '') : (string) ($d['detail'] ?? ''),
            ];
        })->all();
    }

    // ------------------------------------------------------------------
    // DESCRIPTION  (the words staff read — web and mobile share these)
    // ------------------------------------------------------------------

    /**
     * Turn one audit row into a display-ready entry.
     *
     * Provenance comes from source + note, in that order of trust. Note keywords
     * are matched (not equality) because the writer appends "— moved 1.2 km" to
     * the note; the legacy phrasings from before Aug-2026 are matched too, so
     * pins recorded by the older code still read correctly instead of showing a
     * blank badge.
     */
    protected function describe($r): array
    {
        $changes = $this->decode($r->changes);
        $note    = (string) ($r->note ?? '');
        $ignored = $r->action === 'pin_reply_ignored';

        if ($ignored) {
            $kept    = $this->coords($changes, 'kept_latitude', 'kept_longitude');
            $offered = $this->coords($changes, 'offered_latitude', 'offered_longitude');
            $reason  = (string) ($this->changeValue($changes, 'reason') ?? 'existing_pin_kept');
            $needsAction = in_array($reason, self::REASONS_NEEDS_ACTION, true);

            // Wording rules: short words, no jargon, and NO instruction that only
            // fits one screen — this same text renders in the chat (where the save
            // button is right below) AND in the history popover. "Open the chat"
            // was wrong in the chat itself.
            $headline = match ($reason) {
                'qurbani_flow'       => 'Customer sent a pin — waiting for Qurbani review',
                'no_request_pending' => 'Customer sent a pin — not saved (no one had asked for a location)',
                'staff_pin_newer'    => 'Customer sent a pin — our team had already saved a newer one',
                'reply_too_far'      => 'Customer sent a pin — but it is very far from the saved one',
                default              => 'Customer sent a new location — NOT saved',
            };

            $detail = match ($reason) {
                'existing_pin_kept' => 'The old pin is still being used. Check the new one — if it is right, save it.',
                'reply_too_far'     => 'This may be where the customer is right now, not the delivery address. Ask the customer before saving it.',
                default             => $note,
            };

            return $this->row($r, [
                'kind'         => 'ignored',
                'headline'     => $headline,
                'detail'       => $detail,
                'icon'         => '⚠️',
                'tone'         => $needsAction ? 'amber' : 'gray',
                'who'          => 'Customer',
                'needs_action' => $needsAction,
                'reason'       => $reason,
                'old'          => $kept,
                'new'          => null,
                'offered'      => $offered,
                'away_text'    => $this->distanceText($kept, $offered, 'from the saved pin'),
                'moved_text'   => null,
            ]);
        }

        $old = $this->coords($changes, 'latitude', 'longitude', 'old');
        $new = $this->coords($changes, 'latitude', 'longitude', 'new');

        [$headline, $icon, $who] = $this->provenance($r, $note);

        return $this->row($r, [
            'kind'         => 'saved',
            'headline'     => $headline,
            'detail'       => $note,
            'icon'         => $icon,
            'tone'         => $this->isApproximate($note) ? 'blue' : 'green',
            'who'          => $who,
            'needs_action' => false,
            'reason'       => null,
            'old'          => $old,
            'new'          => $new,
            'offered'      => null,
            'away_text'    => null,
            // "first pin set" only when we actually know the new pin — a row with
            // no usable coordinates (a URL-only save) must not claim one was set.
            'moved_text'   => $old
                ? $this->distanceText($old, $new, 'from the previous pin', 'moved ')
                : ($new ? 'first pin set' : null),
        ]);
    }

    /**
     * Who/how, as a headline + icon. Ordered most-specific first: a note we now
     * write is more precise than the surface the request came in on.
     *
     * @return array{0:string,1:string,2:string}  [headline, icon, who]
     */
    protected function provenance($r, string $note): array
    {
        $userName = trim((string) ($r->user_name ?? '')) ?: null;
        $source   = (string) ($r->source ?? '');
        $has      = fn (string $needle) => stripos($note, $needle) !== false;

        // The customer themselves — no staff member involved.
        if ($source === \App\Services\AuditLogger::SOURCE_WHATSAPP) {
            // A replacement is a different event from a first pin: someone on the
            // team asked again, and this answer overwrote what was there. Saying
            // so is the difference between "the pin changed" and "we changed it".
            if ($has('re-request')) {
                return ['Customer sent a new pin after we asked again', '🔁', 'Customer'];
            }
            return [
                $has('map link') ? 'Customer shared a map link on WhatsApp' : 'Customer shared a pin on WhatsApp',
                '💬',
                'Customer',
            ];
        }
        if ($source === 'customer_app' || $has('customer app')) {
            return ['Customer set it in the app', '📱', 'Customer'];
        }

        // Staff, by the surface they used.
        $staff = $userName ?: 'Staff';
        if ($has("pin in the chat")) {
            return ['Saved from the customer\'s chat pin', '💬', $staff];
        }
        if ($has('map link in the chat')) {
            return ['Saved from the customer\'s chat link', '🔗', $staff];
        }
        if ($has('from address')) {
            return ['Pin made from the written address (approximate)', '🌐', $staff];
        }
        if ($has('qurbani')) {
            return ['Qurbani location reply approved', '🐄', $staff];
        }
        if ($has('rider at the door')) {
            return ['Rider dropped the pin at the door', '🏍️', $staff];
        }
        if ($has('store app')) {
            return ['Pin dropped in the store app', '🏬', $staff];
        }
        if ($has('web app')) {
            return ['Pin set on the web app', '💻', $staff];
        }

        // Legacy rows (before Aug-2026) carried only "Verified pin (web|mobile)".
        if ($source === 'mobile') {
            return ['Pin set in the mobile app', '📱', $staff];
        }
        if ($source === 'web') {
            return ['Pin set on the web app', '💻', $staff];
        }
        return ['Pin updated', '📍', $staff];
    }

    /** An address-derived pin is approximate — worth flagging in its own colour. */
    protected function isApproximate(string $note): bool
    {
        return stripos($note, 'from address') !== false || stripos($note, 'approximate') !== false;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Common envelope: timestamps + identity, merged with the type-specific bits. */
    protected function row($r, array $extra): array
    {
        $at = null;
        try {
            $at = $r->at ? Carbon::parse($r->at) : null;
        } catch (\Throwable $e) {
            $at = null;
        }

        return array_merge([
            'id'         => (int) ($r->id ?? 0),
            'at'         => $at ? $at->toIso8601String() : null,
            // Explicit format, never a date cast — a cast renders a day early in
            // JSON on this stack (see the customer-app date-cast bug, Jul-2026).
            'at_display' => $at ? $at->format('d M Y, g:i A') : '',
            'at_human'   => $at ? $at->diffForHumans() : '',
            'user_id'    => $r->user_id ? (int) $r->user_id : null,
            'source'     => (string) ($r->source ?? ''),
            'action'     => (string) ($r->action ?? ''),
        ], $extra);
    }

    protected function decode($json): array
    {
        if (empty($json)) {
            return [];
        }
        try {
            $out = json_decode((string) $json, true);
            return is_array($out) ? $out : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Read {field:{old,new}} → the requested side. */
    protected function changeValue(array $changes, string $field, string $side = 'new')
    {
        return $changes[$field][$side] ?? null;
    }

    /**
     * Pull a lat/lng pair out of the changes map, or null when either side is
     * missing (a URL-only save has no coordinates at all).
     */
    protected function coords(array $changes, string $latKey, string $lngKey, string $side = 'new'): ?array
    {
        $lat = $this->changeValue($changes, $latKey, $side);
        $lng = $this->changeValue($changes, $lngKey, $side);
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        return [
            'latitude'  => (float) $lat,
            'longitude' => (float) $lng,
            'maps_url'  => 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng,
        ];
    }

    /** "1.4 km from the saved pin" / "moved 320 m from the previous pin". */
    protected function distanceText(?array $a, ?array $b, string $suffix, string $prefix = ''): ?string
    {
        if (!$a || !$b) {
            return null;
        }
        try {
            $m = (int) round(\App\Services\LocationService::calculateDistance(
                $a['latitude'], $a['longitude'], $b['latitude'], $b['longitude']
            ));
            return $prefix . \App\Services\LocationService::formatDistance($m) . ' ' . $suffix;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
