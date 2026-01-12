<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $order->order_number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Embedded Unicode font for PDF reliability */
        @font-face {
            font-family: 'InvoiceUnicode';
            src: url('https://fonts.gstatic.com/s/notosans/v36/o-0IIpQlx3QUlC5A4PNr5TRASf6M7Q.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'InvoiceUnicode';
            src: url('https://fonts.gstatic.com/s/notosans/v36/o-0NIpQlx3QUlC5A4PNjXhFVZNyB.woff2') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        @page { margin: 0mm; size: A4; }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
            color: #111827;
            line-height: 1.5;
        }
        /* PDF-specific complete override */
        @if(!empty($isPdf))
        :root { font-size: 14px; }  /* stabilize rems */
        html, body { margin: 0 !important; padding: 0 !important; }
        *, *::before, *::after { box-sizing: border-box; }
        
        body {
            background: #ffffff !important;
            padding: 0 !important;
            margin: 0 !important;
            font-family: 'InvoiceUnicode', 'Noto Sans', 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif !important;
            color: #000000 !important;
        }
        .invoice-container {
            box-shadow: none !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            page-break-inside: avoid !important;
        }
        .invoice-header {
            padding: 12px 16px 8px 16px !important;
            margin-bottom: 0 !important;
            display: table !important;
            width: 100% !important;
            border-bottom: 1px solid #e5e7eb !important;
            page-break-inside: avoid !important;
            page-break-after: avoid !important;
        }
        .logo-section {
            display: table-cell !important;
            vertical-align: top !important;
            width: 50% !important;
        }
        .logo {
            padding: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            background: none !important;
            display: inline-block !important;
        }
        .logo img {
            height: 50px !important;
            width: auto !important;
            max-width: 120px !important;
            display: block !important;
        }
        .company-details {
            display: table-cell !important;
            vertical-align: top !important;
            width: 50% !important;
            text-align: right !important;
            font-size: 9px !important;
            line-height: 1.4 !important;
            color: #6b7280 !important;
        }
        .company-details strong {
            font-weight: 600 !important;
            color: #111827 !important;
        }
        .invoice-title {
            padding: 6px 16px !important;
            margin-bottom: 0 !important;
            text-align: center !important;
            page-break-inside: avoid !important;
            page-break-after: avoid !important;
        }
        .invoice-title h2 {
            margin: 0 !important;
            font-size: 20px !important;
            text-align: center !important;
            font-weight: 700 !important;
            color: #111827 !important;
            letter-spacing: 2px !important;
        }
        .invoice-info {
            padding: 6px 16px !important;
            page-break-inside: avoid !important;
        }
        .invoice-two-col {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        .invoice-col-left {
            width: 60% !important;
            vertical-align: top !important;
            padding-right: 10px !important;
        }
        .invoice-col-right {
            width: 40% !important;
            vertical-align: top !important;
            padding-left: 10px !important;
        }
        .invoice-block {
            margin-bottom: 8px !important;
        }
        .invoice-block h5.title {
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            margin: 0 0 6px 0 !important;
        }
        .invoice-block p {
            font-size: 10px !important;
            line-height: 1.4 !important;
            color: #374151 !important;
            margin: 0 0 2px 0 !important;
        }
        .invoice-block p strong {
            font-weight: 600 !important;
            color: #111827 !important;
        }
        .customer-info h3, .customer-section h3 {
            margin: 0 0 6px 0 !important;
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }
        .customer-details {
            font-size: 10px !important;
            line-height: 1.4 !important;
            color: #374151 !important;
        }
        .customer-details div {
            margin-bottom: 2px !important;
        }
        .customer-name {
            font-size: 10px !important;
            font-weight: 600 !important;
            margin-bottom: 3px !important;
            color: #111827 !important;
        }
        .order-section {
            flex: 0 0 auto !important;
            min-width: 120px !important;
        }
        .order-box {
            border: 1px solid #e5e7eb !important;
            padding: 0 !important;
            background-color: #ffffff !important;
        }
        .order-box-header {
            display: table !important;
            width: 100% !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        .order-box-header-item {
            display: table-cell !important;
            width: 50% !important;
            padding: 4px 6px !important;
            text-align: center !important;
            font-size: 8px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
            background-color: #f9fafb !important;
        }
        .order-box-header-item:first-child {
            border-right: 1px solid #e5e7eb !important;
        }
        .order-box-content {
            display: table !important;
            width: 100% !important;
        }
        .order-box-content-item {
            display: table-cell !important;
            width: 50% !important;
            padding: 5px 6px !important;
            text-align: center !important;
            font-size: 9px !important;
            font-weight: 600 !important;
            color: #111827 !important;
        }
        .order-box-content-item:first-child {
            border-right: 1px solid #e5e7eb !important;
        }
        .products-table {
            margin: 6px 16px !important;
            width: calc(100% - 32px) !important;
            margin-bottom: 0 !important;
            border-collapse: collapse !important;
            border: 1px solid #e5e7eb !important;
            page-break-inside: auto !important;
        }
        .products-table thead {
            background-color: #374151 !important;
            page-break-inside: avoid !important;
        }
        .products-table th {
            padding: 6px 6px !important;
            font-size: 8px !important;
            font-weight: 700 !important;
            color: #ffffff !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
        }
        .products-table tbody tr {
            border-bottom: 1px solid #e5e7eb !important;
            page-break-inside: avoid !important;
        }
        .products-table tbody tr:last-child {
            border-bottom: none !important;
        }
        .products-table td {
            padding: 6px 6px !important;
            font-size: 9px !important;
            color: #374151 !important;
            vertical-align: top !important;
        }
        .product-name {
            font-size: 9px !important;
            font-weight: 600 !important;
            margin-bottom: 0 !important;
            color: #111827 !important;
        }
        .product-sku {
            font-size: 8px !important;
            color: #9ca3af !important;
        }
        .total-items-row {
            padding: 6px 16px !important;
            text-align: left !important;
            font-size: 9px !important;
            font-weight: 600 !important;
            color: #111827 !important;
            border-top: 1px solid #e5e7eb !important;
        }
        .totals-section {
            padding: 6px 16px 8px 16px !important;
            page-break-inside: avoid !important;
        }
        .totals-table {
            max-width: 150px !important;
            border-collapse: collapse !important;
            border: 1px solid #e5e7eb !important;
        }
        .totals-table tr {
            border-bottom: 1px solid #e5e7eb !important;
        }
        .totals-table tr:last-child {
            border-bottom: none !important;
        }
        .totals-table td {
            padding: 5px 8px !important;
            font-size: 9px !important;
        }
        .totals-table .label {
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.2px !important;
            font-size: 8px !important;
            text-align: right !important;
        }
        .totals-table .amount {
            font-weight: 600 !important;
            color: #111827 !important;
            text-align: right !important;
            width: 60px !important;
        }
        .totals-table .total-row {
            background-color: #f9fafb !important;
        }
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            padding: 6px 8px !important;
        }
        .footer {
            padding: 6px 16px !important;
            font-size: 8px !important;
            color: #374151 !important;
            background-color: #f9fafb !important;
            border-top: 1px solid #e5e7eb !important;
            margin-top: 0 !important;
            page-break-inside: avoid !important;
        }
        .footer-message {
            font-size: 9px !important;
            margin-bottom: 4px !important;
            font-weight: 500 !important;
            color: #374151 !important;
            font-style: italic !important;
        }
        .footer-contact {
            font-size: 8px !important;
            color: #6b7280 !important;
            line-height: 1.4 !important;
        }
        
        /* PDF-friendly table layout for reliable alignment */
        .invoice-two-col {
            width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            margin-bottom: 12px !important;
        }
        .invoice-two-col td {
            vertical-align: top !important;
            padding: 0 !important;
        }
        .invoice-col-left { width: 58% !important; }
        .invoice-col-right { width: 42% !important; }
        
        .invoice-block h5, .invoice-block .title {
            margin: 0 0 6px 0 !important;
            font-size: 12px !important;
            font-weight: bold !important;
            color: #000000 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }
        .invoice-block p {
            margin: 0 0 4px 0 !important;
            line-height: 1.3 !important;
            font-size: 11px !important;
            color: #000000 !important;
        }
        
        /* Fixed table layout for products */
        .products-table {
            table-layout: fixed !important;
            width: calc(100% - 24px) !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .products-table th.col-product, .products-table td.col-product { width: 56% !important; white-space: normal !important; }
        .products-table th.col-qty, .products-table td.col-qty { width: 12% !important; text-align: center !important; }
        .products-table th.col-price, .products-table td.col-price { width: 14% !important; text-align: center !important; }
        .products-table th.col-total, .products-table td.col-total { width: 18% !important; text-align: right !important; }
        
        @endif
        
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .invoice-header {
            background-color: white;
            padding: 30px 40px 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
            min-height: 140px;
        }
        
        .logo-section {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        
        .logo img {
            height: 140px;
            width: auto;
            object-fit: contain;
        }
        
        .company-details {
            flex: 0 0 auto;
            text-align: right;
            font-size: 12px;
            line-height: 1.8;
            color: #6b7280;
        }
        
        .company-details div {
            margin-bottom: 2px;
        }
        
        .company-details strong {
            color: #111827;
            font-weight: 600;
        }
        
        .invoice-title {
            background-color: white;
            padding: 20px 40px;
            text-align: center;
        }
        
        .invoice-title h2 {
            font-size: 36px;
            font-weight: 700;
            color: #111827;
            text-align: center;
            letter-spacing: 2px;
            margin: 0;
        }
        
        .invoice-info { 
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 40px;
        }
        
        .customer-section {
            flex: 1;
        }
        
        .order-section {
            flex: 0 0 auto;
            min-width: 280px;
        }
        
        .customer-section h3 {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .customer-details {
            font-size: 13px;
            line-height: 1.4;
            color: #374151;
        }
        
        .customer-details div {
            margin-bottom: 2px;
        }
        
        .customer-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 6px;
        }
        
        /* Order Details Box */
        .order-box {
            border: 2px solid #e5e7eb;
            padding: 0;
            background-color: #ffffff;
        }
        
        .order-box-header {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .order-box-header-item {
            flex: 1;
            padding: 10px 15px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background-color: #f9fafb;
        }
        
        .order-box-header-item:first-child {
            border-right: 1px solid #e5e7eb;
        }
        
        .order-box-content {
            display: flex;
        }
        
        .order-box-content-item {
            flex: 1;
            padding: 12px 15px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }
        
        .order-box-content-item:first-child {
            border-right: 1px solid #e5e7eb;
        }
        
        .products-table {
            width: calc(100% - 80px);
            margin: 20px 40px;
            border-collapse: collapse;
            border: 1px solid #e5e7eb;
        }
        
        .products-table thead {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            color: white;
        }
        
        .products-table th {
            padding: 14px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .products-table th.text-center {
            text-align: center;
        }
        
        .products-table th.text-right {
            text-align: right;
        }
        
        .products-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .products-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .products-table td {
            padding: 14px 12px;
            font-size: 13px;
            color: #374151;
            vertical-align: top;
        }
        
        .products-table td.text-center {
            text-align: center;
        }
        
        .products-table td.text-right {
            text-align: right;
        }
        
        .product-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
            font-size: 13px;
        }
        
        .product-sku {
            font-size: 11px;
            color: #9ca3af;
        }
        
        /* Total Item Number Row */
        .total-items-row {
            padding: 15px 40px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            border-top: 1px solid #e5e7eb;
        }
        
        .totals-section {
            padding: 20px 40px 30px 40px;
        }
        
        .totals-table {
            width: 100%;
            max-width: 350px;
            margin-left: auto;
            border-collapse: collapse;
            border: 1px solid #e5e7eb;
        }
        
        .totals-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .totals-table tr:last-child {
            border-bottom: none;
        }
        
        .totals-table td {
            padding: 12px 20px;
            font-size: 13px;
        }
        
        .totals-table .label {
            text-align: right;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 12px;
        }
        
        .totals-table .amount {
            text-align: right;
            color: #111827;
            font-weight: 600;
            width: 140px;
        }
        
        .totals-table .total-row {
            background-color: #f9fafb;
        }
        
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            padding: 15px 20px;
        }
        
        .footer {
            background-color: #f9fafb;
            padding: 25px 40px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            margin-top: 20px;
        }
        
        .footer-message {
            font-size: 14px;
            color: #374151;
            margin-bottom: 10px;
            font-weight: 500;
            font-style: italic;
        }
        
        .footer-contact {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.6;
        }
        
        @media print {
            body {
                padding: 0;
                background-color: white !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .invoice-container {
                box-shadow: none;
                max-width: none;
            }
            
            .products-table thead {
                background-color: #2d3748 !important;
                color: white !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .totals-table .total-row {
                background-color: #f7fafc !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .footer {
                background-color: #f7fafc !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
@php 
$autoPrint = request('print_pdf') == '1'; 
$autoPng = request('auto_png') == '1'; 
$viewAndDownloadPng = request('view_and_download_png') == '1';
$isPngDownload = $autoPng || $viewAndDownloadPng;
$hideUnitPrice = request('hide_unit_price') == '1';
@endphp
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="logo-section" style="flex:1; display:flex; align-items:flex-start;">
                <div class="logo">
                    <!-- Try multiple logo paths with debugging -->
                <div id="logoContainer" style="width: 100%; height: 100%; position: relative;">
@php
    // Determine a valid logo path and embed as base64 for PDF reliability
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
                    @if((!empty($isPdf) || $isPngDownload) && $logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="Nizami Farms" style="height: 42px; width: auto; display: block;">
                    @else
                        <!-- Preserve robust web fallback with JS as it was working -->
                        <img id="logoImage" src="{{ $webLogo }}" alt="Nizami Farms" 
                             style="border-radius: 6px; max-width: 100%; max-height: 100%;"
                             onerror="tryNextLogo(this)">
                        <script>
                        const logoPaths = [
                            '{{ asset('assets/media/logos/nizami-farms-logo.png') }}',
                            '{{ asset('assets/media/logos/nizami-farms-logo.jpg') }}',
                            '{{ asset('assets/media/logos/nizami-farms-logo.png.jpg') }}',
                            '{{ asset('assets/media/app/nizami-logo.png') }}',
                            '{{ asset('assets/media/logos/logo.png') }}',
                            '{{ asset('logo.png') }}'
                        ];
                        let currentLogoIndex = 0;
                        function tryNextLogo(img){
                            currentLogoIndex++;
                            if(currentLogoIndex < logoPaths.length){ img.src = logoPaths[currentLogoIndex]; }
                        }
                        </script>
                    @endif
                </div>
                </div>
                <!-- Company info should come from logo image, not hardcoded text -->
            </div>
            <div class="company-details" style="flex:1; text-align:right; align-self:flex-start; margin-top:0;">
                <div><strong>NTN: A02148-1</strong></div>
                <div>F-12, Rehman Arcade</div>
                <div>Aabpara Market, G-6/1</div>
                <div>Islamabad</div>
                <div>www.nizamifarms.com</div>
                <div>Ph: 0333-5300905</div>
            </div>
        </div>
        
        <!-- Invoice Title -->
        <div class="invoice-title">
            <h2>INVOICE</h2>
        </div>
        
        <!-- Invoice Information -->
        @if(!empty($isPdf))
        <div class="invoice-info">
            <table class="invoice-two-col">
                <tr>
                    <td class="invoice-col-left">
                        <div class="invoice-block">
                            <h5 class="title">Customer Details</h5>
                            <p><strong>{{ $order->customer->first_name ?? '' }} {{ $order->customer->last_name ?? '' }}</strong></p>
                            @if($order->customer && $order->customer->address1)
                                <p>{{ $order->customer->address1 }}</p>
                                @if($order->customer->address2)
                                    <p>{{ $order->customer->address2 }}</p>
                                @endif
                                <p>{{ $order->customer->city ?? '' }}@if($order->customer->province), {{ $order->customer->province }}@endif</p>
                                @if($order->customer->postal_code)
                                    <p>{{ $order->customer->postal_code }}</p>
                                @endif
                            @endif
                            @if($order->customer && $order->customer->phone_original)
                                <p>{{ $order->customer->phone_original }}</p>
                            @endif
                        </div>
                    </td>
                    <td class="invoice-col-right">
                        <div class="invoice-block" style="text-align: right;">
                            <h5 class="title">Order Summary</h5>
                            <p><strong>Order No:</strong> {{ $order->order_number }}</p>
                            <p><strong>Order Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('j F Y') }}</p>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        @else
        <div class="invoice-info">
            <div class="customer-section">
                <h3>Customer Details</h3>
                <div class="customer-details">
                    <div class="customer-name">{{ $order->customer->first_name ?? '' }} {{ $order->customer->last_name ?? '' }}</div>
                    @if($order->customer && $order->customer->address1)
                        <div>{{ $order->customer->address1 }}</div>
                        @if($order->customer->address2)
                            <div>{{ $order->customer->address2 }}</div>
                        @endif
                        <div>{{ $order->customer->city ?? '' }}@if($order->customer->province), {{ $order->customer->province }}@endif</div>
                        @if($order->customer->postal_code)
                            <div>{{ $order->customer->postal_code }}</div>
                        @endif
                    @endif
                    @if($order->customer && $order->customer->phone_original)
                        <div>{{ $order->customer->phone_original }}</div>
                    @endif
                </div>
            </div>
            <div class="order-section">
                <div class="order-box">
                    <div class="order-box-header">
                        <div class="order-box-header-item">Order No</div>
                        <div class="order-box-header-item">Order Date</div>
                    </div>
                    <div class="order-box-content">
                        <div class="order-box-content-item">{{ $order->order_number }}</div>
                        <div class="order-box-content-item">{{ \Carbon\Carbon::parse($order->order_date)->format('j F Y') }}</div>
                    </div>
                </div>
            </div>
        </div>
        @endif
        
        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    @if($hideUnitPrice)
                    <th style="width: 60%;">PRODUCTS</th>
                    <th class="text-center" style="width: 20%;">Qty</th>
                    <th class="text-right" style="width: 20%;">Total</th>
                    @else
                    <th style="width: 55%;">PRODUCTS</th>
                    <th class="text-center" style="width: 12%;">Qty</th>
                    <th class="text-center" style="width: 15%;">Unit Price</th>
                    <th class="text-right" style="width: 18%;">Total</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($order->lineItems as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->name ?: 'N/A' }}</div>
                    </td>
                    <td class="text-center">{{ number_format($item->quantity, ($item->quantity == floor($item->quantity)) ? 0 : 3) }}</td>
                    @if(!$hideUnitPrice)
                    <td class="text-center">Rs {{ number_format($item->unit_price, 0) }}</td>
                    @endif
                    <td class="text-right" style="font-weight: 600;">Rs {{ number_format($item->line_total ?: ($item->quantity * $item->unit_price), 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Total Item Number -->
        <div class="total-items-row">
            <strong>TOTAL ITEM NUMBER:</strong> {{ $order->lineItems->count() }}
        </div>
        
        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                @php
                    $discountBreakdown = $order->getDiscountBreakdown();
                    $totalDiscounts = $discountBreakdown->sum('discount_amount');
                    $subtotal = $order->lineItems->sum(function($item) { return $item->line_total ?: ($item->quantity * $item->unit_price); });
                @endphp
                
                @if($totalDiscounts > 0)
                    <tr>
                    <td class="label">Discount</td>
                    <td class="amount">- Rs {{ number_format($totalDiscounts, 0) }}</td>
                    </tr>
                @endif
                
                <tr>
                    <td class="label">Sub Total</td>
                    <td class="amount">Rs {{ number_format($subtotal, 0) }}</td>
                </tr>
                
                @if($order->shipping_total && $order->shipping_total > 0)
                <tr>
                    <td class="label">Shipping</td>
                    <td class="amount">Rs {{ number_format($order->shipping_total, 0) }}</td>
                </tr>
                @else
                <tr>
                    <td class="label">Shipping</td>
                    <td class="amount" style="color: #059669; font-weight: 600;">Free Delivery</td>
                </tr>
                @endif
                
                @if(isset($order->tip_amount) && $order->tip_amount > 0)
                <tr>
                    <td class="label">Tip</td>
                    <td class="amount">Rs {{ number_format($order->tip_amount, 0) }}</td>
                </tr>
                @endif
                
                @php
                    // Calculate actual total including tip (in case stored total_price doesn't include it)
                    $calculatedTotal = $subtotal - $totalDiscounts + ($order->shipping_total ?? 0) + ($order->tip_amount ?? 0);
                    // Use the higher of stored total or calculated total (to avoid showing less than owed)
                    $displayTotal = max($order->total_price ?? 0, $calculatedTotal);
                @endphp
                
                <tr class="total-row">
                    <td class="label">Total</td>
                    <td class="amount">Rs {{ number_format($displayTotal, 0) }}</td>
                </tr>
            </table>
        </div>
        
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-message">
                You have trusted us to serve you the best meat in town, thank you!
            </div>
            <div class="footer-contact">
                Follow us on Facebook & Instagram: @nizamifarms, in case of complaints, please contact: 0333-5300905 or write to<br>
                us at: support@nizamifarms.com
            </div>
        </div>
    </div>
<script>
// Auto print-to-PDF for exact browser rendering
(function(){
  const url = new URL(window.location.href);
  if (url.searchParams.get('print_pdf') === '1') {
    // Auto-download PDF using server-side generation for better formatting
    const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
    const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
    const customerName = firstName + (lastName ? ' ' + lastName : '');
    const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
    const orderNumber = '{{ $order->order_number }}';
    const filename = customerName + ' ' + phoneNumber + ' ' + orderNumber;
    const pdfUrl = '/orders/{{ $order->id }}/invoice/pdf?auto_pdf=1&filename=' + encodeURIComponent(filename);
    
    // Try server-side PDF generation first
    fetch(pdfUrl)
      .then(response => {
        if (response.ok) {
          // Server-side PDF generation successful, trigger download
          const link = document.createElement('a');
          link.href = pdfUrl;
          link.download = filename + '.pdf';
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          
          // Close tab after successful download
          setTimeout(() => { window.close(); }, 1000);
        } else {
          throw new Error('Server PDF generation failed');
        }
      })
      .catch(error => {
        console.log('Server PDF failed, falling back to browser print:', error);
        // Fallback to browser print dialog
        document.title = 'Invoice-{{ $order->order_number }}';
        setTimeout(() => { 
          window.print(); 
          // Don't close tab automatically for print dialog
        }, 300);
      });
  }

  if (url.searchParams.get('auto_png') === '1') {
    // Use HTMLCanvas + drawWindow via html2canvas for exact screenshot
    const addScript = (src) => new Promise(r => { const s = document.createElement('script'); s.src = src; s.onload = r; document.head.appendChild(s); });
    (async () => {
      // Load html2canvas from CDN once
      if (!window.html2canvas) {
        await addScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
      }
      const node = document.querySelector('.invoice-container');
      const canvas = await window.html2canvas(node, {scale: 2, useCORS: true});
      const link = document.createElement('a');
      link.href = canvas.toDataURL('image/png');
      const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
      const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
      const customerName = firstName + (lastName ? ' ' + lastName : '');
      const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
      const orderNumber = '{{ $order->order_number }}';
      link.download = customerName + ' ' + phoneNumber + ' ' + orderNumber + '.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      // Close the helper tab/window automatically
      setTimeout(() => { window.close(); }, 500);
    })();
  }

  if (url.searchParams.get('view_and_download_png') === '1') {
    // Use HTMLCanvas + drawWindow via html2canvas for exact screenshot but keep page open
    const addScript = (src) => new Promise(r => { const s = document.createElement('script'); s.src = src; s.onload = r; document.head.appendChild(s); });
    (async () => {
      // Load html2canvas from CDN once
      if (!window.html2canvas) {
        await addScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
      }
      const node = document.querySelector('.invoice-container');
      const canvas = await window.html2canvas(node, {scale: 2, useCORS: true});
      const link = document.createElement('a');
      link.href = canvas.toDataURL('image/png');
      const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
      const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
      const customerName = firstName + (lastName ? ' ' + lastName : '');
      const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
      const orderNumber = '{{ $order->order_number }}';
      link.download = customerName + ' ' + phoneNumber + ' ' + orderNumber + '.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      // Keep the page open so user can view the invoice
    })();
  }
})();

</script>
</body>
</html>
