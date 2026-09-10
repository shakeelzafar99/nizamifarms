<?php

namespace App\Services\Riders;

use App\Services\FirebaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⏰ THE FLEET'S CLOCK — every time-driven sweep in the bikes/maintenance/workshop layer,
 *    in ONE place (10-Sep-2026).
 *
 * WHY THIS EXISTS. For a year every "scheduled" fleet job actually ran only when a screen
 * happened to be opened: the service-due push rode `/service-alerts`, the day-before
 * workshop reminder and the approval escalation rode the workshop alerts poll, and the
 * home-meter escalation rode the rider heartbeat. That was correct while prod had no way
 * to run a command on a clock. It does now — StackCP schedules individual artisan commands
 * (`campaigns:send-process` has run that way since Aug-7) — so the sweeps can finally fire
 * on time rather than "within half an hour of somebody looking".
 *
 * ⭐ ONE IMPLEMENTATION, TWO CALLERS. `fleet:sweep` (the cron) and the request piggybacks
 *   both call the methods below. The piggybacks are deliberately KEPT: they cost nothing,
 *   they are what covers a day the cron is misconfigured, and every push here is deduped
 *   by a ledger or a stamp, so two callers can never double-send.
 *
 * ⚠ Every part is independently try-wrapped and reports a count. A failure in one sweep
 *   must never cost the others their run — and a manager reading the cron output must be
 *   able to see WHICH part did nothing.
 */
class FleetSweepService
{
    /**
     * Run every sweep once. Safe to call as often as you like.
     *
     * @return array{service_pushes:int, workshop_reminders:int, approval_nudges:int,
     *               auto_declined:int, home_meter_escalations:int, errors:string[]}
     */
    public function run(): array
    {
        $out = ['service_pushes' => 0, 'workshop_reminders' => 0, 'approval_nudges' => 0,
                'auto_declined' => 0, 'home_meter_escalations' => 0, 'errors' => []];

        try { $out['service_pushes'] = (new BikeServiceAlerts())->pushDue(); }
        catch (\Throwable $e) { $out['errors'][] = 'service: ' . $e->getMessage(); }

        try { $out = array_merge($out, $this->workshop()); }
        catch (\Throwable $e) { $out['errors'][] = 'workshop: ' . $e->getMessage(); }

        try { $out['home_meter_escalations'] = $this->homeMeter(); }
        catch (\Throwable $e) { $out['errors'][] = 'home-meter: ' . $e->getMessage(); }

        return $out;
    }

    /**
     * 🔧 The workshop half: the day-before rider reminder, the 17:00 nudge to the planners
     *    about a proposal still waiting for tomorrow, and the auto-DECLINE of a proposal whose
     *    day arrived unanswered. Each is once-only by construction (`reminded_at`, the row's
     *    own status), so re-running is a no-op.
     *
     * ⚠ This used to be an inline loop inside `WorkshopVisitController::alerts()`; it lives
     *   here now so the poll and the cron cannot drift. The controller calls this.
     */
    public function workshop(): array
    {
        $out = ['workshop_reminders' => 0, 'approval_nudges' => 0, 'auto_declined' => 0];
        $visits = app(WorkshopVisitService::class);
        $fb     = app(FirebaseService::class);

        foreach ($visits->dueReminders() as $v) {
            try { $fb->notifyWorkshopVisit('reminder', (int) $v['id'], 0); $out['workshop_reminders']++; }
            catch (\Throwable $e) { Log::warning('workshop reminder push failed', ['visit' => $v['id'] ?? null, 'error' => $e->getMessage()]); }
        }

        $esc = $visits->escalateProposals();
        foreach ($esc['nudge'] as $id) {
            try { $fb->notifyWorkshopVisit('approval_reminder', (int) $id, 0); $out['approval_nudges']++; }
            catch (\Throwable $e) { Log::warning('approval nudge push failed', ['visit' => $id, 'error' => $e->getMessage()]); }
        }
        foreach ($esc['declined'] as $id) {
            try { $fb->notifyWorkshopVisit('auto_declined', (int) $id, 0); $out['auto_declined']++; }
            catch (\Throwable $e) { Log::warning('auto-decline push failed', ['visit' => $id, 'error' => $e->getMessage()]); }
        }
        return $out;
    }

    /**
     * 🏠 The overnight-meter escalation: a company-bike rider home 10+ minutes without his
     *    closing reading, or whose window passed. Stamped once per journey
     *    (`home_mgmt_alerted_at`), so the heartbeat piggyback in RiderController and this
     *    sweep can both run without a second push.
     *
     * ⚠ Same loop the heartbeat runs — kept identical on purpose, and small enough to see
     *   that it is.
     */
    public function homeMeter(): int
    {
        if (!Schema::hasColumn('t_ops_attendance', 'home_expected_by')) return 0;
        $hasAlerted = Schema::hasColumn('t_ops_attendance', 'home_mgmt_alerted_at');
        $sent = 0;
        foreach ((new HomeJourneyService())->openEscalations() as $e) {
            if (!empty($e['already_pushed'])) continue;
            try {
                (new FirebaseService())->notifyHomeMeterMissed(
                    $e['attendance_id'], $e['rider_name'], $e['state'], $e['minutes_late']
                );
                $sent++;
            } catch (\Throwable $ex) { /* push best-effort; the stamp below still lands */ }
            if ($hasAlerted) {
                DB::table('t_ops_attendance')->where('id', $e['attendance_id'])
                    ->update(['home_mgmt_alerted_at' => now()]);
            }
        }
        return $sent;
    }
}
