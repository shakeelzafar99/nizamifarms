<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 L1 — audit-trail viewer (2026-07-03).
 *
 * Read-only admin page over t_sys_audit_log. Two modes:
 *  - default: filtered, paginated list (entity type / action / source / user / date).
 *  - ?order=<order_number>: the per-order money+ownership timeline — audit rows
 *    for that order MERGED with its status history, newest first ("everything
 *    that touched order X").
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        // Graceful message if the SQL hasn't been run yet (PHP-first deploy).
        if (!Schema::hasTable('t_sys_audit_log')) {
            return view('audit-log.index', [
                'notReady' => true, 'rows' => null, 'timeline' => null, 'orderNumber' => null,
                'order' => null, 'orderMap' => [], 'entityTypes' => collect(), 'actions' => collect(),
                'filters' => [],
            ]);
        }

        $orderNumber = trim((string) $request->get('order', ''));
        if ($orderNumber !== '') {
            return $this->orderTimeline($orderNumber);
        }

        $q = DB::table('t_sys_audit_log as a')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
            ->select('a.*', 'u.fullname as user_name');

        if ($request->filled('entity_type')) $q->where('a.entity_type', $request->entity_type);
        if ($request->filled('action'))      $q->where('a.action', $request->action);
        if ($request->filled('source'))      $q->where('a.source', $request->source);
        if ($request->filled('user_id'))     $q->where('a.user_id', $request->user_id);
        if ($request->filled('date_from'))   $q->whereDate('a.at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $q->whereDate('a.at', '<=', $request->date_to);

        $rows = $q->orderByDesc('a.id')->paginate(50)->withQueryString();

        // Resolve related order ids -> order numbers for the rows on this page.
        $orderIds = collect($rows->items())->pluck('related_order_id')->filter()->unique()->values()->all();
        $orderMap = $orderIds
            ? DB::table('t_crm_prod_order')->whereIn('id', $orderIds)->pluck('order_number', 'id')->all()
            : [];

        return view('audit-log.index', [
            'notReady'    => false,
            'rows'        => $rows,
            'orderMap'    => $orderMap,
            'entityTypes' => DB::table('t_sys_audit_log')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'actions'     => DB::table('t_sys_audit_log')->distinct()->orderBy('action')->pluck('action'),
            'filters'     => $request->all(),
            'timeline'    => null,
            'orderNumber' => null,
            'order'       => null,
        ]);
    }

    private function orderTimeline(string $orderNumber)
    {
        $order = DB::table('t_crm_prod_order')->where('order_number', $orderNumber)->first();
        $timeline = [];

        if ($order) {
            $auditRows = DB::table('t_sys_audit_log as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->where(function ($w) use ($order) {
                    $w->where('a.related_order_id', $order->id)
                      ->orWhere(function ($x) use ($order) {
                          $x->where('a.entity_type', 'order')->where('a.entity_id', $order->id);
                      });
                })
                ->select('a.*', 'u.fullname as user_name')
                ->get();

            foreach ($auditRows as $r) {
                $timeline[] = [
                    'at'     => (string) $r->at,
                    'user'   => $r->user_name ?: '—',
                    'source' => $r->source,
                    'kind'   => $r->action,
                    'entity' => $r->entity_type . ($r->entity_label ? ' ' . $r->entity_label : ''),
                    'changes'=> $r->changes,
                    'note'   => $r->note,
                ];
            }

            $history = DB::table('t_crm_order_status_history as h')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'h.changed_by')
                ->where('h.order_id', $order->id)
                ->select('h.status_code', 'h.changed_at', 'h.notes', 'u.fullname as user_name')
                ->get();

            foreach ($history as $r) {
                $timeline[] = [
                    'at'     => (string) $r->changed_at,
                    'user'   => $r->user_name ?: '—',
                    'source' => '',
                    'kind'   => 'status → ' . $r->status_code,
                    'entity' => 'status',
                    'changes'=> null,
                    'note'   => $r->notes,
                ];
            }

            usort($timeline, fn ($a, $b) => strcmp($b['at'], $a['at']));
        }

        return view('audit-log.index', [
            'notReady'    => false,
            'timeline'    => $timeline,
            'orderNumber' => $orderNumber,
            'order'       => $order,
            'rows'        => null,
            'orderMap'    => [],
            'entityTypes' => collect(),
            'actions'     => collect(),
            'filters'     => ['order' => $orderNumber],
        ]);
    }
}
