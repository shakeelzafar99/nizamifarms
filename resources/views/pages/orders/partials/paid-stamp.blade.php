{{--
    PAID stamp block — rendered on every invoice view (on-screen, print, image,
    and PDF) when an order is fully paid. The parent view is responsible for
    placing <x-paid-stamp /> via @include('pages.orders.partials.paid-stamp',
    ['order' => $order]) inside a relatively-positioned wrapper, and for
    calling OrderModel::getPaidStampData() once.

    Pure HTML + inline CSS so it survives html2canvas (WhatsApp invoice image),
    Dompdf (server-side PDF), and browser print. No external assets besides
    the company logo which is auto-detected below (matches the fallback
    chain used by the other invoice templates — prod stores it as .png.jpg
    rather than .png).

    Visual design mirrors the physical PAID rubber stamp shown in Corel —
    double red border, all-caps "NIZAMI FARMS" header, huge spaced "P A I D"
    below, rotated ~-8° so it reads like a genuine stamp mark, with a faint
    logo watermark centred behind the text.

    Supports BOTH new (sending_bank + receiving_bank) and legacy (bank)
    shapes so any caller that hand-builds $paidStamp keeps working.
--}}
@php
    // Defensive: the partial can be dropped into any invoice, so compute on
    // the fly rather than requiring the parent to prepare the data.
    $__paid = isset($paidStamp) && is_array($paidStamp)
        ? $paidStamp
        : (method_exists($order ?? null, 'getPaidStampData') ? $order->getPaidStampData() : ['show' => false]);

    // Backwards compatibility: older callers only set 'bank'.
    $__sendingBank   = $__paid['sending_bank']   ?? ($__paid['bank'] ?? '—');
    $__receivingBank = $__paid['receiving_bank'] ?? '';

    // Resolve the logo URL using the same fallback chain the rest of the
    // invoice templates use — on prod the asset is actually named
    // "nizami-farms-logo.png.jpg", so asking for .png returns 404 and the
    // watermark disappears. Pick the first one that exists on disk.
    $__logoUrl = $__paid['logo_url'] ?? null;
    if (!$__logoUrl) {
        $__logoCandidates = [
            'assets/media/logos/nizami-farms-logo.png',
            'assets/media/logos/nizami-farms-logo.jpg',
            'assets/media/logos/nizami-farms-logo.png.jpg',
        ];
        foreach ($__logoCandidates as $__c) {
            if (is_file(public_path($__c))) { $__logoUrl = asset($__c); break; }
        }
        // Final fallback — still return a URL so the <img> has something; a
        // 404 just renders invisibly rather than breaking the layout.
        if (!$__logoUrl) $__logoUrl = asset('assets/media/logos/nizami-farms-logo.png');
    }
@endphp

@if (!empty($__paid['show']))
<div class="nf-paid-stamp" aria-hidden="true"
     style="
        position: relative;
        display: inline-block;
        padding: 14px 22px 10px 22px;
        border: 3px double #991B1B;
        border-radius: 6px;
        font-family: 'Times New Roman', Times, serif;
        color: #991B1B;
        background: transparent;
        transform: rotate(-8deg);
        text-align: center;
        box-shadow: 0 0 0 1px rgba(153,27,27,0.12);
        line-height: 1.1;
        overflow: hidden;
     ">
    {{-- Logo watermark — soft full-coverage "bank chop" style. Kept at
         very low opacity so it reads as an official brand texture
         underneath the stamp typography rather than competing with the
         main "P A I D" word. Mix-blend-mode multiply lets the red text
         sit on top cleanly; browsers that don't support it (Dompdf)
         still render a faint ghost image that looks perfectly fine.
         pointer-events:none so it never captures clicks. --}}
    <img src="{{ $__logoUrl }}" alt=""
         style="
            position: absolute;
            top: 50%;
            left: 50%;
            width: 115px;
            height: 115px;
            margin-top: -57px;
            margin-left: -57px;
            opacity: 0.15;
            mix-blend-mode: multiply;
            pointer-events: none;
            z-index: 0;
         ">

    <div style="position: relative; z-index: 1;">
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
            min-width: 180px;
        ">
            <div style="display:flex; justify-content:space-between; gap:10px;">
                <span style="opacity:.75;">from</span>
                <span style="font-weight:700;">{{ $__sendingBank ?: '—' }}</span>
            </div>
            @if (!empty($__receivingBank))
            <div style="display:flex; justify-content:space-between; gap:10px;">
                <span style="opacity:.75;">to</span>
                <span style="font-weight:700;">{{ $__receivingBank }}</span>
            </div>
            @endif
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
</div>
@endif
