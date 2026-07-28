<?php

/*
|--------------------------------------------------------------------------
| Rider Reports — thresholds (Phase 1, Jul-2026)
|--------------------------------------------------------------------------
| Tunable rules for the rider Report Card + Manager Daily Issues reports.
| Pure static values only (NO env() reads) so config:cache is safe — see the
| project's env/config cache trap note. Change a number here + clear config
| cache to re-tune; no code edit needed.
|
| Distances are metres, durations are minutes, speeds km/h.
*/

return [

    // --- GPS quality gates (applied before any stop/route/proof maths) ---
    'accuracy_max_m'      => 100,   // drop fixes worse than this (kills WiFi/cell phantoms)
    'speed_max_kmh'       => 150,   // drop impossible-speed jumps between kept fixes
    // Trail sources that ARE the delivery pin itself — excluded from "where was he" proof
    'exclude_sources'     => ['delivery', 'manual'],

    // --- Real-time window ---
    // Reports are computed live on open (no job, no stored tables). Only dates
    // within this many days back are offered — keeps it fast and stays inside
    // the ~20-day GPS retention so the trail is always available.
    'live_window_days'    => 7,

    // Day Review (Jul-2026): how long the GPS trail is actually kept. Beyond
    // this the route/stops/gaps genuinely no longer exist, so Day Review says
    // "trail expired" instead of drawing an empty map. Orders + ETA comparison
    // still work for any date inside the controller's MAX_BACK_DAYS reach.
    'trail_retention_days' => 20,

    // --- "Delivered at verified pin" (rider accountability, unified rule) ---
    'at_verified_m'       => 500,   // press within this of the customer's verified pin = OK
    // A fix is only trustworthy to its own accuracy. Up to this many metres of the reported
    // accuracy is allowed as slack before a drop is called "away from the pin", and a fix
    // coarser than this is reported as coarse rather than as the rider having moved.
    // Mirrors the COARSE_FIX_M config row used by the attendance gates.
    'coarse_fix_m'        => 150,

    // --- On-time (delivery ETA) ---
    'late_card_minutes'   => 10,    // Report Card: flag an order later than this vs Google ETA
    'late_manager_minutes'=> 15,    // Manager Issues: only surface orders later than this

    // --- Attendance lateness (check-in vs shift start) ---
    // Only surfaced when the rider is late TODAY *and* his month-to-date total
    // late minutes cross this — so it stays quiet until it's a real pattern.
    'late_month_threshold_min' => 200,

    // --- Stops ---
    'stop_min_minutes'    => 10,    // a cluster this long (or longer) counts as a stop
    'stop_cluster_m'      => 120,   // points within this radius form one stop cluster
    'stop_match_m'        => 300,   // a stop within this of HQ / known place / a customer = "accounted"

    // --- Odd route (between two consecutive drops, route resets at HQ) ---
    'odd_route_ratio'     => 2.2,   // driven vs straight-line ratio
    'odd_route_extra_m'   => 4000,  // AND at least this many extra metres driven

    // --- GPS blackout while on route ---
    'gap_min_minutes'     => 15,

    // --- Door wait (time parked at the customer around the press) ---
    'door_wait_minutes'   => 5,

    // --- "Held up before it" late-reason ---
    'held_up_minutes'     => 10,    // a stop/wait this long just before a drop explains its lateness
    'left_hq_late_minutes'=> 5,     // first order of a wave: stayed at HQ longer than this after dispatch

    /*
    | DEV-ONLY timestamp correction.
    | Production writes every timestamp in PKT, so this stays 0 in prod.
    | On a replica loaded in another timezone, MySQL TIMESTAMP columns
    | (t_crm_order_status_history.changed_at, etc.) can read shifted vs the
    | literal DATETIME columns (GPS captured_at, ETAs). Set this to the shift
    | in MINUTES *only on such a dev box* to align them for local testing.
    | NEVER set it on production. Left at 0 = no correction.
    */
    'ts_offset_minutes'   => 0,
];
