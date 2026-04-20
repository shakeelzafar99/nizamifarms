{{--
    PAID stamp block — rendered on every invoice view (on-screen, print, image,
    and PDF) when an order is fully paid. The parent view is responsible for
    placing <x-paid-stamp /> via @include('pages.orders.partials.paid-stamp',
    ['order' => $order]) inside a relatively-positioned wrapper, and for
    calling OrderModel::getPaidStampData() once.

    Pure HTML + inline CSS so it survives html2canvas (WhatsApp invoice image),
    Dompdf (server-side PDF), and browser print. No external assets, no JS.

    Visual design mirrors the physical PAID rubber stamp shown in Corel —
    double black border, all-caps "NIZAMI ✳ FARMS" header, huge spaced "P A I D"
    below, rotated ~8° clockwise so it reads like a genuine stamp mark.
--}}
@php
    // Defensive: the partial can be dropped into any invoice, so compute on
    // the fly rather than requiring the parent to prepare the data.
    $__paid = isset($paidStamp) && is_array($paidStamp)
        ? $paidStamp
        : (method_exists($order ?? null, 'getPaidStampData') ? $order->getPaidStampData() : ['show' => false]);
@endphp

@if (!empty($__paid['show']))
<div class="nf-paid-stamp" aria-hidden="true"
     style="
        display: inline-block;
        padding: 14px 22px 10px 22px;
        border: 3px double #991B1B;
        border-radius: 6px;
        font-family: 'Times New Roman', Times, serif;
        color: #991B1B;
        background: rgba(255,255,255,0.85);
        transform: rotate(-8deg);
        text-align: center;
        box-shadow: 0 0 0 1px rgba(153,27,27,0.12);
        line-height: 1.1;
     ">
    <div style="
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 2px;
        border-bottom: 2px solid #991B1B;
        padding-bottom: 4px;
        margin-bottom: 6px;
        white-space: nowrap;
    ">NIZAMI&nbsp;&nbsp;FARMS</div>

    <div style="
        font-size: 30px;
        font-weight: 900;
        letter-spacing: 8px;
        padding: 2px 6px 4px 14px;
        white-space: nowrap;
    ">P&nbsp;A&nbsp;I&nbsp;D</div>

    <div style="
        border-top: 2px solid #991B1B;
        padding-top: 6px;
        margin-top: 4px;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .3px;
        font-family: Arial, Helvetica, sans-serif;
        color: #7F1D1D;
        text-align: left;
        min-width: 160px;
    ">
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <span style="opacity:.75;">via</span>
            <span style="font-weight:700;">{{ $__paid['bank'] ?: '—' }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <span style="opacity:.75;">date</span>
            <span style="font-weight:700;">{{ $__paid['date'] }}</span>
        </div>
        @if (!empty($__paid['ref']))
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <span style="opacity:.75;">{{ $__paid['ref_label'] }}</span>
            <span style="font-weight:700; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $__paid['ref'] }}</span>
        </div>
        @endif
    </div>
</div>
@endif
