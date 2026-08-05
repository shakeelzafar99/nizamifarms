{{-- Vendor table rows. Rendered once per business-unit section in a combined scope, or once flat
     in a single-unit scope — one source so the two modes can never drift apart.
     Expects: $rows (vendor row arrays), $scope. --}}
@foreach($rows as $v)
    <tr class="t-row" onclick="window.location='{{ route('fin.hub.vendor', ['id' => $v['id'], 'scope' => $scope]) }}'">
        <td>
            <span class="rider-name" style="gap:8px">{{ $v['name'] }}
                <span class="type-chip">{{ $v['method'] === 'by_weight' ? '⚖ weight' : '📦 total' }}</span>
            </span>
            @if($v['code'])<div class="iv-sub mono" style="color:var(--ink3);margin-left:0">{{ $v['code'] }}</div>@endif
        </td>
        <td class="r"><span class="amt num" style="color:{{ $v['payable'] > 0.5 ? 'var(--owe)' : 'var(--in)' }}">{{ number_format($v['payable'], 2) }}</span></td>
        <td class="r num">{{ $v['purchases'] ? number_format($v['purchases'], 0) : '—' }}</td>
        <td class="r num">{{ $v['payments'] ? number_format($v['payments'], 0) : '—' }}</td>
        <td class="cell-date">{{ $v['last_pay'] ? \Carbon\Carbon::parse($v['last_pay'])->format('M d, Y') : '—' }}</td>
        <td><div class="row-actions" onclick="event.stopPropagation()">
            @if(!auth()->user()?->isReadOnly())
            <button class="mini-btn" type="button" onclick="hubOpenVendorEdit(@js($v))">Edit</button>
            @if($v['deletable'])<button class="mini-btn" type="button" onclick="hubDeleteVendor({{ $v['id'] }}, @js($v['name']))" style="color:var(--out)">Delete</button>@endif
            @endif
            <a class="mini-btn" href="{{ route('fin.hub.vendor', ['id' => $v['id'], 'scope' => $scope]) }}">Ledger →</a>
        </div></td>
    </tr>
@endforeach
