@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $title)
@include('fin.hub.partials.styles')

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => $active, 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    <div class="ph">
        <h2>{{ $title }} — coming next</h2>
        <p>{{ $blurb }} This Hub tab is being built in the next phase. Until then it lives on its existing page, which keeps working exactly as before.</p>
        <a class="btn primary" href="{{ $oldUrl }}">Open “{{ $oldLabel }}” ↗</a>
    </div>
</div>
@endsection
