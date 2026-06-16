@extends('layouts.app')

@section('title', 'Payment Signals — Setup Check')

@section('content')
<div class="container-fluid py-4" style="max-width: 900px;">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0">Online Payment Auto-Matching — Setup Check</h3>
        <span class="badge {{ $allOk ? 'bg-success' : 'bg-warning text-dark' }}" style="font-size: 0.9rem;">
            {{ $allOk ? 'ALL GOOD' : 'NEEDS ATTENTION' }}
        </span>
    </div>

    <p class="text-muted">
        This page verifies that the WhatsApp screenshot reader and the bank-email
        reader are configured correctly. Use <strong>Run now</strong> to process
        pending screenshots immediately (handy for testing — no cron needed).
    </p>

    <div class="mb-4">
        <a href="{{ url('/admin/payments/imap-check') }}?key={{ urlencode($checkSecret) }}&run=1"
           class="btn btn-primary">
            &#9889; Run now (process pending signals)
        </a>
        <a href="{{ url('/admin/payments/imap-check') }}?key={{ urlencode($checkSecret) }}"
           class="btn btn-outline-secondary">
            &#8635; Refresh
        </a>
    </div>

    @if ($ranNow)
        <div class="alert {{ str_starts_with((string) $runOutput, 'ERROR') ? 'alert-danger' : 'alert-info' }}">
            <strong>Run result:</strong>
            <pre class="mb-0 mt-2" style="white-space: pre-wrap; font-size: 0.85rem;">{{ $runOutput }}</pre>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 60px;">Status</th>
                        <th>Check</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($checks as $check)
                        <tr>
                            <td class="text-center">
                                @if ($check['ok'])
                                    <span style="color:#16A34A; font-size:1.4rem;">&#10003;</span>
                                @else
                                    <span style="color:#EA580C; font-size:1.4rem;">&#10007;</span>
                                @endif
                            </td>
                            <td><strong>{{ $check['name'] }}</strong></td>
                            <td class="text-muted small">{{ $check['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @unless ($allOk)
        <div class="alert alert-warning mt-4">
            <strong>What to do:</strong> fix any red rows above. The most common one is the
            PHP <code>imap</code> extension — if that's red, send Bluehost the exact message
            shown in its detail. Everything else is usually a missing line in your
            <code>.env</code> file.
        </div>
    @else
        <div class="alert alert-success mt-4">
            Everything is configured. New WhatsApp screenshots and bank emails will start
            being read and matched automatically.
        </div>
    @endunless

    {{-- ───────────────── Recent signals (live data) ───────────────── --}}
    <div class="d-flex align-items-center justify-content-between mt-5 mb-2">
        <h5 class="mb-0">Recent payment signals</h5>
        @if (!empty($statusCounts))
            <span class="text-muted small">
                @foreach ($statusCounts as $st => $c)
                    <span class="badge bg-light text-dark border">{{ $st }}: {{ $c }}</span>
                @endforeach
            </span>
        @endif
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Conf.</th>
                        <th>Order</th>
                        <th>Reason</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentSignals as $s)
                        <tr>
                            <td>{{ $s['id'] }}</td>
                            <td>{{ $s['source'] }}</td>
                            <td>
                                @php $st = $s['status'] ?? 'new'; @endphp
                                <span class="badge
                                    @if($st==='matched') bg-success
                                    @elseif($st==='amount_mismatch') bg-warning text-dark
                                    @elseif($st==='unmatched') bg-secondary
                                    @elseif($st==='irrelevant') bg-light text-dark border
                                    @elseif($st==='duplicate') bg-info text-dark
                                    @else bg-primary @endif">
                                    {{ $st }}
                                </span>
                            </td>
                            <td>{{ $s['extracted_amount'] !== null ? number_format((float) $s['extracted_amount'], 2) : '—' }}</td>
                            <td>{{ $s['extraction_confidence'] !== null ? $s['extraction_confidence'] : '—' }}</td>
                            <td>{{ $s['matched_order_id'] ?? '—' }}</td>
                            <td class="small text-muted">{{ $s['match_reason'] ?? '—' }}</td>
                            <td class="small text-muted">{{ $s['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-3">No signals yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="text-muted small mt-2">
        A freshly received screenshot shows as <span class="badge bg-primary">new</span>.
        After processing it becomes <span class="badge bg-success">matched</span>,
        <span class="badge bg-warning text-dark">amount_mismatch</span>,
        <span class="badge bg-secondary">unmatched</span>, or
        <span class="badge bg-light text-dark border">irrelevant</span>.
        If it stays <span class="badge bg-primary">new</span> after a Run now, the Gemini call is failing — check the app log.
    </p>
</div>
@endsection
