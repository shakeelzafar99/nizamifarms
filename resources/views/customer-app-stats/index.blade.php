@extends('layouts.app')

@section('title', 'Customer App Stats')

@section('content')
<div class="kt-container-fixed">

    {{-- Header --}}
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-medium leading-none text-mono">📱 Customer App — API Traffic</h1>
            <p class="text-sm text-gray-600">
                One row per request to <code>/api/customer-app/*</code> (including rejected 401/429).
                Use this to size the real rate limit — the current safety backstop is
                <strong>{{ $currentBackstop }} requests/minute</strong>.
            </p>
        </div>
        <div class="flex items-center gap-2">
            @foreach ([1 => 'Today', 7 => '7 days', 14 => '14 days', 30 => '30 days'] as $d => $label)
                <a href="?days={{ $d }}"
                   class="px-3 py-1.5 rounded border text-sm {{ $days === $d ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    @if ($truncated)
        <div class="mb-4 p-3 rounded border border-amber-300 bg-amber-50 text-amber-800 text-sm">
            ⚠️ Log volume exceeded the parsing cap — numbers below cover only the first portion of the window.
        </div>
    @endif

    {{-- Summary cards --}}
    @php
        $avgDur = $totals['durationCount'] > 0 ? (int) round($totals['sumDuration'] / $totals['durationCount']) : null;
        $errRate = $totals['requests'] > 0 ? round(($totals['errors'] / $totals['requests']) * 100, 1) : 0;
        $dayCount = max(1, count($perDay));
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="p-4 rounded-lg border border-gray-200 bg-white">
            <div class="text-xs text-gray-500 mb-1">Total requests</div>
            <div class="text-2xl font-semibold">{{ number_format($totals['requests']) }}</div>
        </div>
        <div class="p-4 rounded-lg border border-gray-200 bg-white">
            <div class="text-xs text-gray-500 mb-1">Avg / day</div>
            <div class="text-2xl font-semibold">{{ number_format((int) round($totals['requests'] / $dayCount)) }}</div>
        </div>
        <div class="p-4 rounded-lg border border-gray-200 bg-white">
            <div class="text-xs text-gray-500 mb-1">Busiest minute</div>
            <div class="text-2xl font-semibold">{{ number_format($peakMinute['count']) }}</div>
            <div class="text-xs text-gray-500">{{ $peakMinute['minute'] ?? '—' }}</div>
        </div>
        <div class="p-4 rounded-lg border border-gray-200 bg-white">
            <div class="text-xs text-gray-500 mb-1">Unique customers/orders</div>
            <div class="text-2xl font-semibold">{{ number_format($uniqueIdCount) }}</div>
        </div>
        <div class="p-4 rounded-lg border border-gray-200 bg-white">
            <div class="text-xs text-gray-500 mb-1">Error rate (4xx/5xx)</div>
            <div class="text-2xl font-semibold {{ $errRate > 5 ? 'text-red-600' : '' }}">{{ $errRate }}%</div>
            <div class="text-xs text-gray-500">{{ number_format($totals['errors']) }} errors</div>
        </div>
        <div class="p-4 rounded-lg border border-gray-200 bg-white">
            <div class="text-xs text-gray-500 mb-1">Avg / max duration</div>
            <div class="text-2xl font-semibold">{{ $avgDur !== null ? $avgDur . 'ms' : '—' }}</div>
            <div class="text-xs text-gray-500">max {{ number_format($totals['maxDuration']) }}ms</div>
        </div>
    </div>

    {{-- Limit advice --}}
    <div class="mb-6 p-4 rounded-lg border {{ $suggestedLimit ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-gray-50' }} text-sm">
        @if ($suggestedLimit)
            💡 Based on the busiest observed minute ({{ $peakMinute['count'] }} requests), a comfortable real limit
            would be around <strong>{{ $suggestedLimit }}/min</strong> (10× peak). The current backstop of
            {{ $currentBackstop }}/min is {{ $currentBackstop >= $suggestedLimit ? 'above' : 'BELOW' }} that —
            revisit once traffic grows.
        @else
            No traffic recorded yet in this window. Once the customer app goes live, come back here to see real
            numbers before tightening the {{ $currentBackstop }}/min backstop.
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Per-day table --}}
        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 font-medium">Requests per day</div>
            <div style="overflow-x:auto;">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2 text-right">Requests</th>
                            <th class="px-4 py-2 text-right">Errors</th>
                            <th class="px-4 py-2 text-right">Peak min</th>
                            <th class="px-4 py-2 text-right">Avg ms</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($perDay as $date => $row)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-2">{{ $date }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($row['count']) }}</td>
                                <td class="px-4 py-2 text-right {{ $row['errors'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ number_format($row['errors']) }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($perDayPeak[$date] ?? 0) }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['durCnt'] > 0 ? (int) round($row['sumDur'] / $row['durCnt']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No requests in this window</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Per-endpoint table --}}
        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 font-medium">Requests per endpoint</div>
            <div style="overflow-x:auto;">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                            <th class="px-4 py-2">Endpoint</th>
                            <th class="px-4 py-2 text-right">Requests</th>
                            <th class="px-4 py-2 text-right">Errors</th>
                            <th class="px-4 py-2 text-right">Avg ms</th>
                            <th class="px-4 py-2 text-right">Max ms</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($perRoute as $route => $row)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-2"><code class="text-xs">{{ str_replace('api/customer-app/', '', $route) }}</code></td>
                                <td class="px-4 py-2 text-right">{{ number_format($row['count']) }}</td>
                                <td class="px-4 py-2 text-right {{ $row['errors'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ number_format($row['errors']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['durCnt'] > 0 ? (int) round($row['sumDur'] / $row['durCnt']) : '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($row['maxDur']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No requests in this window</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Status codes + recent errors --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 font-medium">Response status codes</div>
            <div class="p-4 flex flex-wrap gap-2">
                @forelse ($statusCounts as $code => $count)
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium border
                        {{ $code >= 500 ? 'bg-red-50 border-red-300 text-red-800' : ($code >= 400 ? 'bg-amber-50 border-amber-300 text-amber-800' : 'bg-green-50 border-green-300 text-green-800') }}">
                        {{ $code }} <span class="opacity-70">×{{ number_format($count) }}</span>
                    </span>
                @empty
                    <span class="text-gray-400 text-sm">—</span>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 font-medium">Recent errors (last 25)</div>
            <div style="overflow-x:auto; max-height:280px; overflow-y:auto;">
                <table class="w-full text-sm">
                    <tbody>
                        @forelse ($recentErrors as $err)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">{{ $err['at'] }}</td>
                                <td class="px-4 py-2"><code class="text-xs">{{ str_replace('api/customer-app/', '', $err['route']) }}</code></td>
                                <td class="px-4 py-2 text-xs">{{ $err['id'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-right"><span class="text-red-600 font-medium">{{ $err['status'] }}</span></td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-center text-gray-400">No errors in this window 🎉</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-xs text-gray-400 mt-6">
        Source: <code>storage/logs/customer_app-YYYY-MM-DD.log</code> (kept {{ config('logging.channels.customer_app.days', 30) }} days).
        Written by <code>LogCustomerAppRequest</code> middleware after each response — zero latency added to the customer app.
    </p>
</div>
@endsection
