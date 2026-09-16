@extends('layouts.app')

{{--
    🔎 THE BIKE ISSUES BOARD — standalone page (Sep-15 2026).

    ⭐⭐ WHY THIS PAGE EXISTS AT ALL. The owner asked for a read-only door for the shift planners.
       The obvious answer — let them into the Bikes tab — cannot work: Farooq's only role is TYPED
       `rider`, and `OrderController::ridersMap()` redirects any role-typed rider off the riders-map
       page before a single bike key is consulted. He even holds `view_rider_reports`, which the
       Bikes data gate accepts; the PAGE gate is what stops him. Changing his role type to get him
       in would also hand him the live board, Day Review and the rider money views, because he
       holds `view_orders` and `view_all_riders`.

       So: its own route, gated on HOLDING A KEY rather than on a role type (a plain rider holds
       none of them), rendering THE SAME partial the Bikes tab mounts. One board, two doors.

    ⚠ No costs, no month picker, no map — this page is the board and nothing else. What a viewer
      may actually DO is decided by the endpoint (`can_manage` / `threads` / `read_only`), not by
      this file, so a read-only planner is never offered a control the server would refuse.
--}}

@section('title', 'Bike issues')

@section('content')
<div style="max-width:1400px;margin:0 auto;padding:18px 0 40px;">

    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:0 16px 12px;">
        <div>
            <h4 style="margin:0;font-weight:700;">🛠 Bike issues</h4>
            <div style="font-size:12.5px;color:#6b7280;margin-top:2px;">
                What is stuck on each machine, and whose turn it is.
            </div>
        </div>
        {{-- ⚠ Only offered to someone who can actually open Bikes. A planner following this link
             would land on the riders-map redirect, which reads as the app being broken. --}}
        @if(auth()->user() && (auth()->user()->hasPermission('view_bike_costs')
             || auth()->user()->hasPermission('view_rider_reports')
             || auth()->user()->hasPermission('web_menu_finance_hub')))
            <a href="/riders-map#bikes" class="btn btn-sm btn-outline-secondary" style="margin-left:auto;">
                Open the full Bikes screen →
            </a>
        @endif
    </div>

    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
        {{-- ⚠ FL_ISS_INLINE_THREADS is deliberately NOT set here: flOpenTicket() lives in
             fleet.blade.php and is not loaded on this page, so ticket rows LINK into Bikes
             instead. A second thread renderer is exactly what this round refuses to add. --}}
        @include('pages.riders-map.partials.fleet-issues')
    </div>
</div>

{{-- 🔧 The workshop corner cards. A planner's one job on this page is answering them, and this
     partial renders nothing for anyone who is not an audience. --}}
@include('partials.workshop-alerts')

<script>
    // The partial opens hidden because the Bikes tab toggles it; here it IS the page.
    document.addEventListener('DOMContentLoaded', function () {
        var w = document.getElementById('flIssWrap');
        if (w) w.style.display = '';
        if (typeof flIssLoad === 'function') { flIssLoad(); flIssPoll(); }
    });
</script>
@endsection
