<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Services\HR\DayReviewService;
use Illuminate\Http\Request;

/**
 * Day review — the manager's queue of days that need a judgement, and the verdicts he gives.
 * (September 2026. Plan: DAY-REVIEW-AND-LEAVE-PANEL-PLAN-SEP2026.md)
 *
 * ⭐ Gated by the existing `manage_payroll` (owner ruling: Shabib + Taimur, no new permission),
 * so the audience is edited exactly where every other payroll permission already is.
 *
 * ⚠ Nothing here decides anything on its own. An unreviewed day counts in full — these
 * endpoints only record what a manager has actually looked at.
 */
class DayReviewController extends Controller
{
    private function gate(): bool
    {
        $u = auth()->user();
        return $u && $u->hasPermission('manage_payroll');
    }

    /** The bulb's number. Deliberately tiny — the web pill polls this every 60 seconds. */
    public function pendingCount(Request $request)
    {
        if (!$this->gate()) {
            return response()->json(['success' => true, 'count' => 0]);   // no access → no bulb
        }
        $svc = app(DayReviewService::class);
        return response()->json([
            'success' => true,
            'count'   => $svc->pendingCount(),
            'enabled' => $svc->enabled(),
        ]);
    }

    /** The queue itself: the cards a manager reads. */
    public function pending(Request $request)
    {
        if (!$this->gate()) {
            return response()->json(['success' => false, 'message' => 'No access.'], 403);
        }
        $svc = app(DayReviewService::class);
        $opts = ['limit' => min(200, max(1, (int) $request->input('limit', 60)))];
        if ($request->filled('user_id')) { $opts['user_id'] = (int) $request->input('user_id'); }
        if ($request->filled('from'))    { $opts['from'] = substr((string) $request->input('from'), 0, 10); }
        if ($request->filled('to'))      { $opts['to'] = substr((string) $request->input('to'), 0, 10); }

        return response()->json([
            'success' => true,
            'enabled' => $svc->enabled(),
            'start'   => $svc->startDate(),
            'items'   => $svc->pending($opts),
        ]);
    }

    /**
     * Every reviewable day for one employee in a month — pending AND already judged.
     * This is what the payroll drill lists, so a manager can revisit his own decisions.
     */
    public function forMonth(Request $request)
    {
        if (!$this->gate()) {
            return response()->json(['success' => false, 'message' => 'No access.'], 403);
        }
        $userId = (int) $request->input('user_id');
        $month  = (string) $request->input('month');
        if (!$userId || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['success' => false, 'message' => 'Bad request'], 400);
        }
        $svc = app(DayReviewService::class);
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));
        return response()->json([
            'success' => true,
            'enabled' => $svc->enabled(),
            'start'   => $svc->startDate(),
            'items'   => $svc->itemsFor($userId, $start, $end, true),
            'summary' => $svc->summary($userId, $month),
        ]);
    }

    /** Record one verdict. The service owns every rule; this only shapes the request. */
    public function record(Request $request)
    {
        if (!$this->gate()) {
            return response()->json(['success' => false, 'message' => 'No access.'], 403);
        }
        $data = $request->validate([
            'user_id' => 'required|integer',
            'date'    => 'required|date_format:Y-m-d',
            'kind'    => 'required|in:overtime,late',
            'verdict' => 'required|in:verified,adjusted,waived',
            'minutes' => 'nullable|integer|min:0',
            'waived'  => 'nullable|integer|min:0',
            'reason'  => 'nullable|string|max:200',
        ]);

        $res = app(DayReviewService::class)->record(
            (int) $data['user_id'], $data['date'], $data['kind'], $data['verdict'],
            (int) (auth()->id() ?? 0),
            [
                'minutes' => $data['minutes'] ?? null,
                'waived'  => $data['waived'] ?? null,
                'reason'  => $data['reason'] ?? null,
            ]
        );
        return response()->json($res, $res['success'] ? 200 : 422);
    }
}
