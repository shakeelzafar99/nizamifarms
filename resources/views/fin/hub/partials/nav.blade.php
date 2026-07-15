{{-- Ledger Hub chrome: title, tab bar, business-unit scope switcher.
     Expects: $active (tab key), $scope, $canSeeKhaas, $canSeeMulti. --}}
@php
    $scope = $scope ?? 'ops';
    $tabs = [
        'overview' => ['Overview', 'fin.hub.overview'],
        'accounts' => ['Accounts', 'fin.hub.accounts'],
        'vendors'  => ['Vendors',  'fin.hub.vendors'],
        'banks'    => ['Banks',    'fin.hub.banks'],
        'health'   => ['Health',   'fin.hub.health'],
    ];
    $scopeOpts = [
        'ops'     => ['NF + Frozen', 'linear-gradient(135deg,var(--in) 50%,var(--owe) 50%)', 'Operations — both units, Qurbani kept separate'],
        'nf'      => ['NF', 'var(--in)', 'Nizami Farms (fresh) only'],
        'khaas'   => ['Frozen', 'var(--owe)', 'Frozen unit — its own tills, sales split by product'],
        'qurbani' => ['Qurbani', '#0EA5A0', 'Seasonal book — its own cash & online accounts'],
    ];
    $activeScopeNote = $scopeOpts[$scope][2] ?? '';
@endphp

<div class="hub-head">
    <div>
        <div class="hub-title">
            <h1>Ledger Hub</h1>
            <span class="beta">beta</span>
        </div>
        <p class="hub-sub">One place for cash, riders, vendors &amp; banks — same numbers as the mobile app.</p>
    </div>
    <div class="hub-actions">
        <a class="btn ghost" href="{{ route('fin.ledger.index') }}" title="The current ledger page — kept live for cross-checking">Old ledger ↗</a>
        @if(!auth()->user()?->isReadOnly())
        <button class="btn" type="button" onclick="hubOpenTransfer()">⇄ New Transfer</button>
        @endif
    </div>
</div>

@include('fin.hub.partials.transfer-modal')

<div class="hub-tabs" role="tablist">
    @foreach($tabs as $key => [$label, $routeName])
        <a class="hub-tab {{ $active === $key ? 'on' : '' }}"
           href="{{ route($routeName, ['scope' => $scope]) }}">{{ $label }}</a>
    @endforeach
</div>

@if($canSeeMulti)
<div class="scope-bar">
    <span class="sb-label">Scope</span>
    <div class="scope-seg" role="group" aria-label="Business scope">
        @foreach($scopeOpts as $key => [$label, $dot, $note])
            @php
                // Single-NF users never reach here; multi users see all four.
                $show = $canSeeMulti || $key === 'nf' || ($key === 'khaas' && $canSeeKhaas);
            @endphp
            @if($show)
                <a class="{{ $scope === $key ? 'on' : '' }}"
                   href="{{ request()->fullUrlWithQuery(['scope' => $key]) }}">
                    <span class="sdot" style="background:{{ $dot }}"></span>{{ $label }}
                </a>
            @endif
        @endforeach
    </div>
    <span class="scope-note">{{ $activeScopeNote }}</span>
</div>
@endif
