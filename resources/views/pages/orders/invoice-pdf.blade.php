<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice PDF</title>
    <style>
        /* 1) Sane defaults */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { 
            margin: 0; 
            padding: 0; 
            background: #fff; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', 'Helvetica Neue', Arial, sans-serif; 
            line-height: 1.5; 
            font-size: 12px; 
            color: #111827; 
        }

        /* 2) Page + margins for print */
        @page { 
            size: A4; 
            margin: 14mm 20mm 16mm 16mm; 
        }
        
        @media print {
            html, body { width: 210mm; }
            thead { display: table-header-group; }
            tr, td, th { page-break-inside: avoid; }
        }

        /* 3) Layout container */
        .wrapper { 
            width: 100% !important; 
            max-width: 100% !important; 
            padding: 0 4mm 0 0;
        }

        /* 4) Images & tables behave */
        img { max-width: 100%; height: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; vertical-align: top; }

        /* 5) Header */
        .header { 
            width: 100%; 
            margin-bottom: 14px; 
            border-bottom: 1px solid #e5e7eb; 
            padding-bottom: 16px; 
        }
        .header td { vertical-align: top; padding: 2px 0; }
        .header td:first-child { width: 45%; }
        .header td:last-child { width: 55%; padding-right: 4mm; text-align: right; }
        .logo { height: 75px; object-fit: contain; }
        .company { 
            text-align: right; 
            font-size: 11px; 
            line-height: 1.6; 
            color: #6b7280; 
            padding-right: 0;
        }
        .company strong { color: #111827; font-weight: 600; }
        .company div { margin-bottom: 3px; }

        /* 6) Title */
        .title { 
            text-align: center; 
            margin: 20px 0 16px; 
            font-size: 30px; 
            font-weight: 700; 
            letter-spacing: 2px; 
        }

        /* 7) Customer + order box */
        .info-table { 
            width: 100%; 
            margin: 12px 0 16px; 
        }
        .info-left { 
            width: 58%; 
            vertical-align: top; 
            padding-right: 24px; 
        }
        .info-right { 
            width: 42%; 
            vertical-align: top; 
        }
        
        .info-label { 
            text-transform: uppercase; 
            font-weight: 700; 
            font-size: 11px; 
            letter-spacing: 0.8px; 
            margin-bottom: 8px; 
            color: #111827;
        }
        
        .customer-name { 
            font-weight: 600; 
            margin-bottom: 6px; 
            font-size: 12.5px;
            color: #111827;
        }
        
        .text { 
            font-size: 12px; 
            line-height: 1.6; 
            color: #374151; 
        }
        .text div { margin-bottom: 3px; }

        /* Order box */
        .order-box { 
            border: 2px solid #d1d5db; 
            border-radius: 6px; 
            overflow: hidden; 
            width: 100%;
        }
        .order-box-header { 
            background: #f9fafb; 
            display: table; 
            width: 100%;
        }
        .order-box-header th { 
            display: table-cell;
            width: 50%;
            text-align: center; 
            padding: 10px 12px; 
            font-size: 10.5px; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            color: #374151; 
            border-right: 1px solid #e5e7eb;
        }
        .order-box-header th:last-child { border-right: none; }
        
        .order-box-body { 
            display: table; 
            width: 100%;
        }
        .order-box-body td { 
            display: table-cell;
            width: 50%;
            text-align: center; 
            padding: 12px; 
            font-size: 13px; 
            font-weight: 600; 
            color: #111827; 
            border-right: 1px solid #e5e7eb;
        }
        .order-box-body td:last-child { border-right: none; }

        /* 8) Products table */
        .items { 
            margin-top: 18px; 
            border: 1px solid #e5e7eb; 
            border-radius: 4px;
            overflow: hidden;
            width: 100%; 
        }
        .items thead { 
            background: #111827; 
            color: #fff; 
        }
        .items thead th { 
            font-size: 11px; 
            letter-spacing: 0.5px; 
            padding: 12px 14px;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
        }
        .items tbody tr { 
            border-bottom: 1px solid #f0f2f4; 
        }
        .items tbody tr:nth-child(even) { 
            background: #fafafa; 
        }
        .items tbody td { 
            font-size: 12px; 
            padding: 11px 14px; 
            color: #374151; 
        }
        .items tbody td:first-child { 
            font-weight: 600; 
            color: #111827; 
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .product-sku { 
            display: block; 
            margin-top: 2px; 
            font-size: 10.5px; 
            color: #9ca3af; 
        }

        /* 9) Totals panel */
        .totals-wrapper { 
            margin-top: 20px; 
            padding-top: 16px;
            border-top: 2px solid #e5e7eb;
            width: 100%; 
        }
        .totals { 
            width: 370px; 
            float: right; 
            border: 1px solid #e5e7eb; 
            border-radius: 6px; 
            overflow: hidden; 
        }
        .totals-row { 
            display: table; 
            width: 100%; 
            padding: 10px 16px; 
            border-bottom: 1px solid #f0f2f4;
        }
        .totals-row:last-child { border-bottom: none; }
        .totals-label { 
            display: table-cell;
            text-transform: uppercase; 
            font-weight: 700; 
            font-size: 11px; 
            letter-spacing: 0.5px;
            width: 55%;
        }
        .totals-amount { 
            display: table-cell;
            font-weight: 600; 
            font-size: 12.5px;
            text-align: right;
            width: 45%;
        }
        .totals-total { 
            background: #f9fafb; 
            font-weight: 700;
        }

        /* 10) Footer */
        .footer { 
            clear: both; 
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #f9fafb; 
            border-top: 1px solid #e5e7eb; 
            padding: 14px 16mm; 
            text-align: center; 
        }
        .footer p { 
            margin: 0 0 5px 0; 
            font-size: 11px; 
            color: #374151; 
            line-height: 1.5;
        }
        .footer p:last-child { margin-bottom: 0; }
        
        /* Add bottom padding to content to prevent overlap with fixed footer */
        .wrapper {
            padding-bottom: 80px;
        }
    </style>
</head>
<body>
@php
    // Reuse robust logo lookup from web template for PDF reliability
    $webLogo = asset('assets/media/logos/nizami-farms-logo.png');
    $paths = [
        public_path('assets/media/logos/nizami-farms-logo.png'),
        public_path('assets/media/logos/nizami-farms-logo.jpg'),
        public_path('assets/media/logos/nizami-farms-logo.png.jpg'),
    ];
    $logoDataUri = null;
    foreach ($paths as $p) {
        if (is_file($p)) {
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
            break;
        }
    }
@endphp
    <div class="wrapper">
        <!-- Header -->
        <table class="header">
            <tr>
                <td>
                    <img class="logo" src="{{ $logoDataUri ?? ($webLogo ?? '') }}" alt="Logo">
                </td>
                <td class="company">
                    <div><strong>NTN:</strong> A02148-1</div>
                    <div>F-12, Rehman Arcade</div>
                    <div>Aabpara Market, G-6/1</div>
                    <div>Islamabad</div>
                    <div>www.nizamifarms.com</div>
                    <div>Ph: 0305-5300905</div>
                    <div>0320-0NIZAMI (0649264)</div>
                </td>
            </tr>
        </table>

        <div class="title">INVOICE</div>

        <!-- Info -->
        @php
            // ⭐ PRIORITY: Use ORDER address fields (for order-specific overrides)
            // Fallback to customer table only if order fields are empty
            $custFirstName = $order->address_first_name ?: ($order->customer->first_name ?? '');
            $custLastName = $order->address_last_name ?: ($order->customer->last_name ?? '');
            $custName = trim("$custFirstName $custLastName");
            
            // If still empty, try order.name field
            if (empty($custName) && $order->name) {
                $custName = $order->name;
            }
            
            // Address fields - order first, then customer fallback
            $address1 = $order->address_line1 ?: ($order->customer->address1 ?? '');
            $address2 = $order->address_line2 ?: ($order->customer->address2 ?? '');
            $city = $order->address_city ?: ($order->customer->city ?? '');
            $province = $order->address_province ?: ($order->customer->province ?? '');
            $postalCode = $order->address_postal_code ?: ($order->customer->postal_code ?? '');
            $phone = $order->address_phone ?: ($order->customer->phone_original ?? '');
        @endphp
        <table class="info-table">
            <tr>
                <td class="info-left">
                    <div class="info-label">CUSTOMER DETAILS</div>
                    <div class="customer-name">{{ $custName }}</div>
                    <div class="text">
                        @if($address1)
                            <div>{{ $address1 }}</div>
                            @if($address2)
                                <div>{{ $address2 }}</div>
                            @endif
                            <div>{{ $city }}@if($province), {{ $province }}@endif</div>
                            @if($postalCode)
                                <div>{{ $postalCode }}</div>
                            @endif
                        @endif
                        @if($phone)
                            <div>{{ $phone }}</div>
                        @endif
                    </div>
                </td>
                <td class="info-right">
                    <div class="order-box">
                        <div class="order-box-header">
                            <th>ORDER NO</th>
                            <th>ORDER DATE</th>
                        </div>
                        <div class="order-box-body">
                            <td>{{ $order->order_number }}</td>
                            <td>{{ \Carbon\Carbon::parse($order->order_date)->format('j F Y') }}</td>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Items -->
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 55%">PRODUCTS</th>
                    <th class="center" style="width: 10%">Qty</th>
                    <th class="center" style="width: 15%">Unit Price</th>
                    <th class="right" style="width: 20%">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->lineItems as $item)
                @php
                    $isQurbaniInvoice = !empty($qurbaniInvoiceFields ?? []);
                    $hasItemNote = $isQurbaniInvoice && !empty(trim((string)($item->instructions ?? '')));
                @endphp
                <tr{!! $hasItemNote ? ' style="background-color: #fefce8;"' : '' !!}>
                    <td>{{ $item->name ?: 'N/A' }}@if($item->is_free) <span style="font-size: 9px; font-weight: 700; color: #16a34a;">[FREE]</span>@endif
                        @if($isQurbaniInvoice)
                        @php
                            $attrParts = [];
                            $fieldMap = ['qurbani_day' => 'Day', 'qurbani_delivery_type' => 'Type', 'qurbani_slot' => 'Slot', 'qurbani_region' => 'Region', 'qurbani_sub_region' => 'Sub Region', 'qurbani_type' => 'Qurbani Type', 'qurbani_paya' => 'Paya'];
                            foreach ($qurbaniInvoiceFields as $f) { $val = $item->{$f} ?? null; if ($val) $attrParts[] = ($fieldMap[$f] ?? $f) . ': ' . $val; }
                        @endphp
                        @if(count($attrParts) > 0)
                        <div style="font-size: 10px; color: #92400e; font-weight: 500; line-height: 1.4; margin-top: 2px;">
                            @foreach($attrParts as $attrPart)<div>{{ $attrPart }}</div>@endforeach
                        </div>
                        @endif
                        @endif
                        @if($hasItemNote)<br><span style="font-size: 8px; color: #92400e; font-style: italic;">📝 {{ $item->instructions }}</span>@endif
                    </td>
                    <td class="text-center">{{ number_format($item->quantity, ($item->quantity == floor($item->quantity)) ? 0 : 3) }}</td>
                    <td class="text-center">@if($item->is_free)<s style="color:#aaa;">Rs&nbsp;{{ number_format($item->unit_price, 0) }}</s>@else Rs&nbsp;{{ number_format($item->unit_price, 0) }}@endif</td>
                    <td class="text-right">@if($item->is_free)<span style="color: #16a34a; font-weight: 700;">FREE</span>@else Rs&nbsp;{{ number_format($item->line_total ?: ($item->quantity * $item->unit_price), 0) }}@endif</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if(!empty($qurbaniInvoiceFields ?? []) && !empty(trim((string)($order->note ?? ''))))
        <div style="margin: 6px 0; padding: 6px 8px; background: #fefce8; border: 1px solid #fde68a; border-radius: 4px;">
            <span style="font-size: 9px; color: #92400e; font-weight: 700;">📝 Order Notes:</span>
            <span style="font-size: 9px; color: #78350f;"> {{ $order->note }}</span>
        </div>
        @endif

        <!-- Totals + PAID stamp (stamp renders only when fully paid) -->
        @php
            $discountBreakdown = $order->getDiscountBreakdown();
            $totalDiscounts = $discountBreakdown->sum('discount_amount');
            $subtotal = $order->lineItems->sum(function($item) { return $item->is_free ? 0 : ($item->line_total ?: ($item->quantity * $item->unit_price)); });
            $__paidStampData = $order->getPaidStampData();
        @endphp
        <div class="totals-wrapper" style="position:relative;">
            @if ($__paidStampData['show'])
                <div class="paid-stamp-wrap" style="float:left; padding-left:20px; padding-top:10px; max-width:45%;">
                    @include('pages.orders.partials.paid-stamp', ['order' => $order, 'paidStamp' => $__paidStampData])
                </div>
            @endif
            <div class="totals">
                {{-- ⭐ Account balance is the customer's OWN money, never a discount.
                     Split lines; the sum (and the total below) is unchanged. --}}
                @php
                    $__balanceUsed = $order->accountBalanceApplied();
                    $__realDiscount = round($totalDiscounts - $__balanceUsed, 2);
                @endphp
                @if($__realDiscount > 0)
                <div class="totals-row">
                    <div class="totals-label">DISCOUNT</div>
                    <div class="totals-amount">- Rs&nbsp;{{ number_format($__realDiscount, 0) }}</div>
                </div>
                @endif
                @if($__balanceUsed > 0)
                <div class="totals-row">
                    <div class="totals-label">ACCOUNT BALANCE USED</div>
                    <div class="totals-amount">- Rs&nbsp;{{ number_format($__balanceUsed, 0) }}</div>
                </div>
                @endif
                <div class="totals-row">
                    <div class="totals-label">SUB TOTAL</div>
                    <div class="totals-amount">Rs&nbsp;{{ number_format($subtotal, 0) }}</div>
                </div>
                @php
                    // Qurbani self-collection rule — see invoice.blade.php
                    // for the detailed reasoning. Kept inline here rather
                    // than pulled into a helper because every invoice
                    // variant renders independently (no shared partial)
                    // and we want zero behaviour change if one of them
                    // ever gets templated differently.
                    $qSelfCollect = function ($v) {
                        $s = strtolower(trim((string)($v ?? '')));
                        return $s !== '' && str_contains($s, 'self');
                    };
                    $hasAnyQurbaniDT = false;
                    $allQurbaniDTSelfCollect = true;
                    foreach (($order->lineItems ?? []) as $__li) {
                        $__dt = $__li->qurbani_delivery_type ?? null;
                        if ($__dt) {
                            $hasAnyQurbaniDT = true;
                            if (!$qSelfCollect($__dt)) { $allQurbaniDTSelfCollect = false; }
                        }
                    }
                    if (!$hasAnyQurbaniDT && !empty($order->qurbani_delivery_type)) {
                        $hasAnyQurbaniDT = true;
                        $allQurbaniDTSelfCollect = $qSelfCollect($order->qurbani_delivery_type);
                    }
                    $hideShippingRow = $hasAnyQurbaniDT && $allQurbaniDTSelfCollect
                                       && (float)($order->shipping_total ?? 0) <= 0;
                @endphp
                @if($order->shipping_total && $order->shipping_total > 0)
                <div class="totals-row">
                    <div class="totals-label">SHIPPING</div>
                    <div class="totals-amount">Rs&nbsp;{{ number_format($order->shipping_total, 0) }}</div>
                </div>
                @elseif(!$hideShippingRow)
                <div class="totals-row">
                    <div class="totals-label">SHIPPING</div>
                    <div class="totals-amount" style="color: #059669; font-weight: 600;">Free Delivery</div>
                </div>
                @endif
                @if(isset($order->tip_amount) && $order->tip_amount > 0)
                <div class="totals-row">
                    <div class="totals-label">TIP</div>
                    <div class="totals-amount">Rs&nbsp;{{ number_format($order->tip_amount, 0) }}</div>
                </div>
                @endif
                @php
                    // Calculate actual total including tip (in case stored total_price doesn't include it)
                    $calculatedTotal = $subtotal - $totalDiscounts + ($order->shipping_total ?? 0) + ($order->tip_amount ?? 0);
                    // Use the higher of stored total or calculated total (to avoid showing less than owed)
                    $displayTotal = max($order->total_price ?? 0, $calculatedTotal);
                @endphp
                <div class="totals-row totals-total">
                    <div class="totals-label">TOTAL</div>
                    <div class="totals-amount">Rs&nbsp;{{ number_format($displayTotal, 0) }}</div>
                </div>
            </div>
            <div style="clear:both;"></div>
        </div>

        <div class="footer">
            <p>You have trusted us to serve you the best meat in town, thank you!</p>
            <p>Follow us on Facebook &amp; Instagram: @nizamifarms • Complaints: 0305-5300905 or 0320-0NIZAMI (0649264) • support@nizamifarms.com</p>
        </div>
    </div>
</body>
</html>


