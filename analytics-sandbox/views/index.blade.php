@extends('layouts.app')

@section('title', 'Analytics Sandbox')

@section('content')
<div class="kt-container-fixed py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Analytics Sandbox</h1>
            <p class="text-sm text-gray-500 mt-1">
                Prototype dashboards built against real production data, behind real auth, inside the real layout.
                Nothing here is wired into the production menu — these are previews.
            </p>
        </div>
        <span class="kt-badge kt-badge-warning kt-badge-outline">Sandbox · {{ $user?->name ?? 'guest' }}</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        {{-- Worked example: Qurbani --}}
        <a href="/sandbox/qurbani"
           class="kt-card p-5 hover:shadow-md transition border border-gray-200 rounded-xl block">
            <div class="flex items-center gap-3 mb-2">
                <i class="ki-filled ki-chart-line-up text-2xl text-amber-600"></i>
                <h3 class="text-base font-semibold text-gray-900">Qurbani analytics</h3>
            </div>
            <p class="text-sm text-gray-500">
                Cohorts, conversion funnel, loyalty ladder, season ops.
                Mirrors the Vercel prototype.
            </p>
            <span class="text-xs text-amber-600 mt-3 inline-block">Worked example · clone this →</span>
        </a>

        {{-- Empty slots for the developer to fill in --}}
        <div class="kt-card p-5 border border-dashed border-gray-300 rounded-xl text-center text-sm text-gray-400">
            <i class="ki-filled ki-plus text-2xl mb-2 block"></i>
            Add new dashboard:<br>
            <code class="text-xs">analytics-sandbox/views/&lt;page&gt;.blade.php</code>
        </div>
        <div class="kt-card p-5 border border-dashed border-gray-300 rounded-xl text-center text-sm text-gray-400">
            <i class="ki-filled ki-plus text-2xl mb-2 block"></i>
            Add new dashboard:<br>
            <code class="text-xs">analytics-sandbox/views/&lt;page&gt;.blade.php</code>
        </div>

    </div>

    <div class="mt-8 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
        <strong>Reminder:</strong>
        edits in this repo are restricted to <code>analytics-sandbox/</code>.
        See <code>analytics-sandbox/README.md</code>, <code>CONTEXT.md</code>, and <code>LEARNINGS.md</code> before writing queries.
    </div>
</div>
@endsection
