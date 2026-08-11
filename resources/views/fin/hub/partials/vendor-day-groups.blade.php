{{-- Day groups for ONE month of a vendor's statement.

     Rendered two ways — inline by vendor-detail, and on its own as the response to the lazy-load
     endpoint when a collapsed month is opened. Both go through this file so an on-demand month can
     never differ in markup or balances from one rendered with the page.

     Expects: $days (day groups, DESC), $vendor, $account, $oldUrl. --}}
@php $readOnly = auth()->user()?->isReadOnly(); @endphp
@foreach($days as $g)
    @php
        $net = $g['purchases'] - $g['payments'];
        if (abs($net) < 0.005) { $netCls = 'balanced'; $netTxt = '✓ Even'; }
        elseif ($net > 0) { $netCls = 'holding'; $netTxt = 'Owed + Rs. ' . number_format($net, 0); }
        else { $netCls = 'balanced'; $netTxt = 'Paid Rs. ' . number_format(abs($net), 0); }
    @endphp
    <div class="day-group">
        <div class="day-head">
            <b>{{ \Carbon\Carbon::parse($g['date'])->format('D, M d, Y') }}</b>
            <span><span class="s-pur {{ $g['purchases'] > 0 ? '' : 'z' }}">📦 Rs. {{ number_format($g['purchases'], 0) }}</span> · <span class="s-pay {{ $g['payments'] > 0 ? '' : 'z' }}">💵 Rs. {{ number_format($g['payments'], 0) }}</span></span>
            @if(!$readOnly)
                {{-- Quick-add for THIS day (the old page had these): purchase opens prefilled with
                     the date; payment also prefills the day's still-owed net as the amount.
                     Each is tinted like the column it writes into — amber grows the debt, green pays it. --}}
                <span class="day-add">
                    @if($vendor->default_purchase_method === 'by_weight')
                        <button class="mini-btn tint-owe" type="button" onclick="hubOpenWeighted('{{ $g['date'] }}')" title="Add a purchase dated {{ \Carbon\Carbon::parse($g['date'])->format('M d') }}">＋ ⚖</button>
                    @else
                        <button class="mini-btn tint-owe" type="button" onclick="hubOpenPurchase('{{ $g['date'] }}')" title="Add a purchase dated {{ \Carbon\Carbon::parse($g['date'])->format('M d') }}">＋ 📦</button>
                    @endif
                    <button class="mini-btn tint-in" type="button" onclick="hubOpenPayment('{{ $g['date'] }}', {{ $net > 0 ? round($net, 2) : 0 }})" title="Add a payment dated {{ \Carbon\Carbon::parse($g['date'])->format('M d') }}{{ $net > 0 ? ' — prefills the day’s Rs. ' . number_format($net, 0) : '' }}">＋ 💵</button>
                </span>
            @endif
            <span class="day-net {{ $netCls }}">{{ $netTxt }}</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Time</th><th>Type</th><th>Description</th><th class="r">Purchase</th><th class="r">Payment</th><th class="r">Balance</th><th class="r">Actions</th></tr></thead>
                <tbody>
                @foreach($g['items'] as $it)
                    @php
                        $r = $it['row'];
                        $isP = $it['is_purchase'];
                        $desc = trim((string) $r->description);
                        $d = [
                            'id' => $r->id, 'url' => route('fin.ledger.show', $r->id),
                            'title' => $isP ? 'Vendor purchase' : 'Vendor payment',
                            'sub' => $desc !== '' ? \Illuminate\Support\Str::limit($desc, 90) : '—',
                            'amount' => 'Rs. ' . number_format($r->amount, 2), 'dir' => $isP ? 'owe' : 'in',
                            'mode' => ucfirst($r->mode ?? 'cash'),
                            // Which of OUR banks — only set when the payment went through one.
                            'bank' => optional($r->receivingAccount)->short_code ?: optional($r->receivingAccount)->name,
                            'from' => optional($r->fromAccount)->account_name ?? '—', 'fromsub' => optional($r->fromAccount)->account_code ?? '',
                            'to' => $vendor->vendor_name, 'tosub' => optional($account)->account_code ?? '',
                            'status' => 'ok', 'statusLabel' => 'Approved',
                            'date' => \Carbon\Carbon::parse($r->transaction_date)->format('M d, Y'),
                            // fullname, not name — UserModel has no `name`, so ->name is always NULL
                            'by' => optional($r->createdBy)->fullname ?? '—', 'pending' => false,
                            'entered' => $r->created_at ? $r->created_at->format('M d, Y · g:i A') : null,
                            // Turns on the drawer's inline quick edit. Set ONLY here: the drawer is
                            // shared with the Accounts / Banks / Overview pages, where the vendor
                            // edit endpoints and modals do not exist. Whether the row needs the
                            // simple or the line-item editor is decided in the drawer, from the
                            // fetched transaction — not guessed from the vendor's purchase method,
                            // because a by-weight vendor can still hold plain purchases.
                            'editable' => !$readOnly,
                        ];
                    @endphp
                    {{-- t-row + data-d must stay: the drawer binds a delegated listener to
                         '.t-row[data-d]'. e-purchase / e-payment only add the colour. --}}
                    <tr class="t-row {{ $isP ? 'e-purchase' : 'e-payment' }}" data-d='{{ json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'>
                        <td class="cell-date num">{{ $r->created_at ? $r->created_at->format('H:i') : '' }}</td>
                        <td><span class="type-chip {{ $isP ? 'tc-purchase' : 'tc-payment' }}">{{ $isP ? 'Purchase' : 'Payment' }}</span></td>
                        @php
                            // 📎 chip: images_count when the multi-image table is live (withCount
                            // in vendorLedger), else presence of the legacy mirror column.
                            $imgN = $r->images_count ?? ($r->bill_image ? 1 : 0);
                        @endphp
                        <td class="desc" title="{{ $desc }}">{{ $desc !== '' ? \Illuminate\Support\Str::limit($desc, 46) : '—' }}@if($imgN > 0) <button class="bill-chip" type="button" onclick="event.stopPropagation();hubViewImages({{ $r->id }}, @json($r->bill_image))" title="View the attached bill / receipt image{{ $imgN > 1 ? 's' : '' }}">📎{{ $imgN > 1 ? '×' . $imgN : '' }}</button>@endif</td>
                        <td class="r">@if($isP)<span class="amt owe num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                        <td class="r">@if(!$isP)<span class="amt in num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                        <td class="r num" style="color:{{ $it['running'] > 0.5 ? 'var(--owe)' : 'var(--ink2)' }}">{{ number_format($it['running'], 2) }}</td>
                        <td>
                            <div class="row-actions" onclick="event.stopPropagation()">
                                @if(!$readOnly)
                                    @if($isP && $vendor->default_purchase_method === 'by_weight')
                                        {{-- Line items are editable IN the Hub now (this used to
                                             bounce to the old page). A by-weight vendor can still
                                             have a plain purchase — the handler detects that and
                                             opens the simple editor instead. --}}
                                        <button class="mini-btn" type="button" onclick="hubOpenWeightedEdit({{ $r->id }})">Edit</button>
                                    @else
                                        <button class="mini-btn" type="button"
                                            data-edit='{{ json_encode(['id' => $r->id, 'amount' => (float) $r->amount, 'date' => \Carbon\Carbon::parse($r->transaction_date)->format('Y-m-d'), 'desc' => $desc, 'label' => $isP ? 'purchase' : 'payment', 'bill' => $r->bill_image ?: null], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'
                                            onclick="hubOpenEditTxn(JSON.parse(this.dataset.edit))">Edit</button>
                                    @endif
                                    <button class="mini-btn danger" type="button" onclick="hubDeleteTxn({{ $r->id }})">Delete</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach
