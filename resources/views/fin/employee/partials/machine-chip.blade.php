{{--
    ⛽🏍 WHICH MACHINE THIS CLAIM'S MONEY IS FOR.

    One pill, used by BOTH the Petrol and the Maintenance card on Daily Closing, so the
    two panels can never drift apart in wording. The words themselves are composed
    server-side (`VehicleResolver::machineChip`) and are byte-identical here, in the
    Month view popup and on the phone — a UI must never re-derive "is it a van", which is
    exactly how a company van once got drawn as a bike.

    ⚠ Deliberately ONE pill style per panel (white on the panel's own border colour). The
      icon and the words carry personal / company / van; adding a third colour axis to a
      screen this busy would cost more than it explains.

    Expects:
      $req   — a request row carrying the vehicle_* keys (all null when unstamped)
      $tone  — the panel's border colour, so the pill sits inside its own card
--}}
@php($mcTone = $tone ?? '#fdba74')
@if(!empty($req['vehicle_kind']))
    <span class="text-xs px-1.5 py-0.5 rounded font-semibold"
          style="background:#ffffff; border:1px solid {{ $mcTone }}; color:#374151; white-space:nowrap;"
          title="The machine this claim is filed against{{ !empty($req['vehicle_plate_note']) ? ' — ' . strtolower($req['vehicle_plate_note']) : '' }}">
        {{ $req['vehicle_icon'] }} {{ $req['vehicle_text'] }}
    </span>
@else
    {{-- Never guessed from the day's machine: a two-machine day is precisely when this
         matters, so a guess would be a confident wrong answer. Same words the Fleet
         screens already use for an unstamped claim. --}}
    <span class="text-xs px-1.5 py-0.5 rounded font-semibold"
          style="background:#fef3c7; color:#b45309; white-space:nowrap;"
          title="This claim does not name a machine. Claims filed before August 2026 were not stamped; open the rider's Month view to see what he rode that day.">
        ❓ machine not recorded
    </span>
@endif
