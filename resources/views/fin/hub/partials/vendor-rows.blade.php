{{-- Vendor table rows. Rendered once per business-unit section in a combined scope, or once flat
     in a single-unit scope — one source so the two modes can never drift apart.
     Expects: $rows (vendor row arrays), $scope, $stateFn (state resolver from vendors.blade.php).

     Each row carries its state in colour (left bar + tint + pill) and the data-* attributes the
     page's filter chips read. States, defined once in vendors.blade.php:
       owes | stale (owes + 30d+ since a payment) | settled | idle --}}
@foreach($rows as $v)
    @php
        $state = $stateFn($v);
        // Days since the last payment — drives the freshness line and the "silent" pill.
        $days = $v['last_pay']
            ? (int) abs(\Carbon\Carbon::parse($v['last_pay'])->startOfDay()->diffInDays(\Carbon\Carbon::now()->startOfDay()))
            : null;
        $agoText = $days === null ? 'no payment yet'
            : ($days === 0 ? 'today' : ($days === 1 ? 'yesterday' : $days . ' days ago'));
        $agoCls = $days === null ? 'old' : ($days <= 1 ? 'fresh' : ($days >= 30 ? 'old' : ''));

        $statusMap = [
            'stale'   => ['stale', $days === null ? 'owes · never paid' : 'owes · silent ' . $days . 'd'],
            'owes'    => ['owes', 'owes'],
            'settled' => ['ok', 'settled'],
            'idle'    => ['idle', 'idle this period'],
        ];
        [$statusCls, $statusText] = $statusMap[$state];

        // Amount colour: red when owing and gone quiet, amber when owing, grey at zero,
        // green only when the balance is genuinely the other way (NF is in credit).
        $amtCls = $v['payable'] > 0.5 ? ($state === 'stale' ? 'out' : 'owe')
            : (abs($v['payable']) < 0.005 ? 'zero' : 'in');

        // Initials: strip leading punctuation first so "Asad (Saidpur)" reads AS, not "A(".
        $parts = preg_split('/\s+/', trim((string) $v['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_filter(
            array_map(fn ($p) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $p), $parts),
            fn ($p) => $p !== ''
        ));
        $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    @endphp
    <tr class="v-row st-{{ $state }}"
        data-state="{{ $state }}" data-method="{{ $v['method'] }}" data-bu="{{ $v['bu'] }}"
        onclick="window.location='{{ route('fin.hub.vendor', ['id' => $v['id'], 'scope' => $scope]) }}'">
        <td>
            <span class="v-name"><span class="avatar">{{ $initials }}</span>{{ $v['name'] }}
                <span class="type-chip {{ $v['method'] === 'by_weight' ? 'tc-weight' : 'tc-total' }}">{{ $v['method'] === 'by_weight' ? '⚖ weight' : '📦 total' }}</span>
            </span>
            @if($v['code'])<span class="v-code">{{ $v['code'] }}</span>@endif
        </td>
        <td><span class="status {{ $statusCls }}">{{ $statusText }}</span></td>
        <td class="r"><span class="amt {{ $amtCls }} num">{{ number_format($v['payable'], 2) }}</span></td>
        <td class="r cell-num num">{{ $v['purchases'] ? number_format($v['purchases'], 0) : '—' }}</td>
        <td class="r cell-num num">{{ $v['payments'] ? number_format($v['payments'], 0) : '—' }}</td>
        <td class="cell-date">
            {{ $v['last_pay'] ? \Carbon\Carbon::parse($v['last_pay'])->format('M d, Y') : '—' }}
            <span class="ago {{ $agoCls }}">{{ $agoText }}</span>
        </td>
        <td><div class="row-actions" onclick="event.stopPropagation()">
            @if(!auth()->user()?->isReadOnly())
            <button class="mini-btn" type="button" onclick="hubOpenVendorEdit(@js($v))">Edit</button>
            @if($v['deletable'])<button class="mini-btn danger" type="button" onclick="hubDeleteVendor({{ $v['id'] }}, @js($v['name']))">Delete</button>@endif
            @endif
            <a class="mini-btn solid-info" href="{{ route('fin.hub.vendor', ['id' => $v['id'], 'scope' => $scope]) }}">Ledger →</a>
        </div></td>
    </tr>
@endforeach
