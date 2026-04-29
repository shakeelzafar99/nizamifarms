<?php

namespace AnalyticsSandbox\Controllers;

/**
 * Worked example: the Qurbani analytics dashboard prototype.
 *
 * This mirrors the cards on the Vercel prototype at
 * https://nizamifarms.vercel.app/qurbani so the developer has a 1:1 starting
 * point. The SQL files in `analytics-sandbox/queries/` carry GUESSED column
 * names marked with `TODO: confirm` — the developer's job is to replace those
 * with real columns by reading `app/Models/CRM/*` and `database/migrations/`.
 */
class QurbaniSandboxController extends SandboxController
{
    public function index()
    {
        // Pull each card's data from a separate raw SQL file. Keeping queries
        // out of PHP makes them easy for the repo owner to review.
        $cohorts = $this->runQueryFile('qurbani-cohorts.example.sql');

        // Massage the cohorts result into the shape the view expects.
        // Right now this is intentionally minimal — the developer will add
        // more cards (conversion funnel, loyalty ladder, season ops, etc.)
        // following this pattern.
        $cards = [
            'newCustomers2025' => $this->extract($cohorts, 'year', 2025, 'new_customers'),
            'totalCohort2025'  => $this->extract($cohorts, 'year', 2025, 'cohort_size'),
        ];

        return view('analytics-sandbox::qurbani', $this->viewContext([
            'cohorts' => $cohorts,
            'cards'   => $cards,
        ]));
    }

    /**
     * Tiny helper to pluck a single value out of the result rows.
     */
    private function extract(array $rows, string $matchKey, $matchValue, string $valueKey)
    {
        foreach ($rows as $row) {
            if (($row->{$matchKey} ?? null) == $matchValue) {
                return $row->{$valueKey} ?? null;
            }
        }
        return null;
    }
}
