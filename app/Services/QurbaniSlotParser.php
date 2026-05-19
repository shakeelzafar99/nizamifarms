<?php

namespace App\Services;

/**
 * Parses Qurbani slot strings like "Afternoon 10 AM to 2 PM" or
 * "Evening 4 PM" into [start_minute, end_minute] (minutes since
 * midnight) so on-time / at-risk dashboard math becomes a cheap
 * integer comparison.
 *
 * Background:
 *   The qurbani_slot column on t_crm_prod_order_line_item is a
 *   user-typed string. Production has 20 distinct values today,
 *   all in exactly two patterns:
 *     1. "<Period> <Start> to <End>"   (e.g. "Afternoon 10 AM to 2 PM")
 *     2. "<Period> <Time>"             (e.g. "Afternoon 2 PM")
 *
 *   The leading period word (Morning/Afternoon/Evening) is purely
 *   decorative — the AM/PM tokens give us actual hours. We DO NOT
 *   trust the period word for AM/PM disambiguation; the explicit
 *   AM/PM is required and authoritative.
 *
 *   For single-time slots we synthesize end = start + 60 so the
 *   dashboard's late detection still has a window. The repo owner
 *   can override per-slot later by editing the slot string to a
 *   range form ("Afternoon 2 PM to 3 PM" → end=900 explicitly).
 *
 * Used by:
 *   - app/Console/Commands/QurbaniBackfillSlotMinutes.php  (one-off)
 *   - app/Models/CRM/OrderLineItemModel boot hook         (per save)
 *   - Future dashboard endpoints that need slot-end comparisons.
 */
class QurbaniSlotParser
{
    /**
     * Synthetic window for single-time slots, in minutes.
     * Single-time slots have no explicit end — we treat them as
     * "deliver within this hour" by default. Tunable here if the
     * repo owner later wants a tighter / looser default.
     */
    private const SINGLE_SLOT_DEFAULT_WINDOW_MINUTES = 60;

    /**
     * Parse a slot string into [start_minute, end_minute] or
     * [null, null] when the string is empty or doesn't match a
     * known pattern. Pure function — no side effects, no DB.
     *
     * Examples:
     *   "Afternoon 10 AM to 2 PM" → [600, 840]
     *   "Evening 7 PM to 9 PM"    → [1140, 1260]
     *   "Afternoon 2 PM"          → [840, 900]
     *   "Morning 9 AM"            → [540, 600]
     *   "  evening  3:30 pm "     → [930, 990]   (whitespace + case tolerant)
     *   ""                        → [null, null]
     *   "weird thing"             → [null, null]
     *
     * @return array{0:?int,1:?int}
     */
    public static function parse(?string $slot): array
    {
        if ($slot === null) return [null, null];
        $clean = trim($slot);
        if ($clean === '') return [null, null];

        // Normalise: collapse whitespace, uppercase. Period word
        // (MORNING/AFTERNOON/EVENING) gets stripped — we already
        // have AM/PM markers so it's redundant for math.
        $norm = strtoupper(preg_replace('/\s+/', ' ', $clean));
        $norm = preg_replace('/^(MORNING|AFTERNOON|EVENING)\s+/', '', $norm);

        // Range pattern: "10 AM TO 2 PM" or "10:30 AM TO 2:00 PM"
        if (preg_match('/^(\d{1,2}(?::\d{2})?)\s*(AM|PM)\s+TO\s+(\d{1,2}(?::\d{2})?)\s*(AM|PM)$/', $norm, $m)) {
            $start = self::timeToMinutes($m[1], $m[2]);
            $end   = self::timeToMinutes($m[3], $m[4]);
            if ($start === null || $end === null) return [null, null];

            // Guard against malformed ranges (end before start).
            // Real production data has none today — this is just
            // defensive.
            if ($end < $start) return [null, null];

            return [$start, $end];
        }

        // Single-time pattern: "2 PM" or "11:30 AM"
        if (preg_match('/^(\d{1,2}(?::\d{2})?)\s*(AM|PM)$/', $norm, $m)) {
            $start = self::timeToMinutes($m[1], $m[2]);
            if ($start === null) return [null, null];
            $end = $start + self::SINGLE_SLOT_DEFAULT_WINDOW_MINUTES;
            // Cap at end-of-day so we never wrap past midnight.
            // Eid orders never cross midnight per repo owner.
            if ($end > 1439) $end = 1439;
            return [$start, $end];
        }

        return [null, null];
    }

    /**
     * Helper for callers that just want the end (the dashboard's
     * primary interest is "is the slot end approaching?").
     */
    public static function endMinute(?string $slot): ?int
    {
        return self::parse($slot)[1];
    }

    /**
     * Convert "10:30" + "PM" (or "10" + "AM") to minutes since
     * 00:00. Handles the 12-hour edge cases (12 AM = midnight,
     * 12 PM = noon). Returns null if the input is malformed.
     */
    private static function timeToMinutes(string $time, string $ampm): ?int
    {
        if (!preg_match('/^(\d{1,2})(?::(\d{2}))?$/', $time, $m)) return null;
        $hour = (int) $m[1];
        $min  = isset($m[2]) ? (int) $m[2] : 0;

        if ($hour < 1 || $hour > 12) return null;
        if ($min < 0 || $min > 59) return null;

        $ampm = strtoupper($ampm);
        // 12 AM → 0, 12 PM → 12, 1 PM → 13, 11 PM → 23 etc.
        if ($hour === 12) $hour = 0;
        if ($ampm === 'PM') $hour += 12;

        return $hour * 60 + $min;
    }

    /**
     * Compare an event timestamp (typically the Google ETA for an
     * out-for-delivery item, or the actual qurbani_delivered_at for
     * a delivered one) to the slot's end-of-window. Returns a
     * structured result the UI can show as a coloured chip without
     * doing any math itself.
     *
     * Phase 4 (May-2026) — used by:
     *   • RiderController::getQurbaniRiderRoute (mobile bundle cards)
     *   • QurbaniWebController::getOrderItems   (web orders cards)
     *   • RiderController::getQurbaniOrderTimeline (timeline modal)
     *
     * Logic:
     *   - Take only the time-of-day part of $eventTimestamp (orders
     *     never cross midnight per repo owner).
     *   - Compare to $slotEndMinute. Signed diff = event - slot_end
     *     (positive = past slot end).
     *   - Apply $graceMinutes window: anything within +grace of
     *     slot_end is still considered within slot.
     *
     * Variants of $context:
     *   - 'eta'       (default) — for OFD items, label uses "ETA"
     *   - 'delivered' — for delivered items, label uses "Delivered"
     *
     * Returns null when we can't compute (no event, no slot end).
     *
     * @return array{
     *   state: 'within'|'late'|'unknown',
     *   diff_minutes: int,         // signed: positive = past slot end
     *   event_minute: int,
     *   slot_end_minute: int,
     *   label: string              // emoji + short text
     * }|null
     */
    public static function compareEventToSlot(
        ?string $eventTimestamp,
        ?int $slotEndMinute,
        int $graceMinutes = 10,
        string $context = 'eta'
    ): ?array {
        if ($eventTimestamp === null || $slotEndMinute === null) return null;
        try {
            $dt = \Carbon\Carbon::parse($eventTimestamp);
            $eventMin = $dt->hour * 60 + $dt->minute;
            $diff = $eventMin - $slotEndMinute; // positive = past slot end
            if ($diff <= $graceMinutes) {
                $within = $slotEndMinute - $eventMin;
                $label = $context === 'delivered'
                    ? ($within > 0
                        ? "🟢 Delivered within slot ({$within} min before end)"
                        : '🟢 Delivered within slot')
                    : ($within > 0
                        ? "🟢 ETA within slot ({$within} min before end)"
                        : '🟢 ETA within slot');
                return [
                    'state'           => 'within',
                    'diff_minutes'    => $diff,
                    'event_minute'    => $eventMin,
                    'slot_end_minute' => $slotEndMinute,
                    'label'           => $label,
                ];
            }
            $label = $context === 'delivered'
                ? "🔴 Delivered {$diff} min past slot"
                : "🟡 ETA {$diff} min past slot";
            return [
                'state'           => 'late',
                'diff_minutes'    => $diff,
                'event_minute'    => $eventMin,
                'slot_end_minute' => $slotEndMinute,
                'label'           => $label,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reverse: convert minutes-since-midnight to a 12-hour clock
     * string for display. Used by the dashboard renderer.
     */
    public static function formatMinutes(?int $minutes): ?string
    {
        if ($minutes === null) return null;
        if ($minutes < 0 || $minutes > 1440) return null;
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return $m === 0
            ? sprintf('%d %s', $h12, $ampm)
            : sprintf('%d:%02d %s', $h12, $m, $ampm);
    }
}
