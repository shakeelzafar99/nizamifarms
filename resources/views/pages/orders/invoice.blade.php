<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $order->order_number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
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
        @page { margin: 3mm 8mm 8mm 8mm; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
            color: #1a202c;
            line-height: 1.5;
        }
        /* PDF-specific complete override */
        @if(!empty($isPdf))
        :root { font-size: 14px; }  /* stabilize rems */
        html, body { margin: 0; padding: 0; }
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
        }
        .invoice-header {
            padding: 8px 12px !important;
            margin-bottom: 6px !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            min-height: 30px !important;
        }
        .logo-section {
            flex: 0 0 auto !important;
            display: flex !important;
            align-items: flex-start !important;
            width: 120px !important;
        }
        .logo {
            width: 120px !important;
            height: 30px !important;
            padding: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            background: none !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
        }
        .logo img {
            height: 28px !important;
            width: auto !important;
            max-width: 120px !important;
            display: block !important;
        }
        .company-details {
            flex: 1 !important;
            text-align: right !important;
            font-size: 10px !important;
            line-height: 1.3 !important;
            margin-top: 0 !important;
            align-self: flex-start !important;
            padding-left: 10px !important;
            color: #000000 !important;
        }
        .company-details strong {
            font-weight: bold !important;
            color: #000000 !important;
        }
        .invoice-title {
            padding: 4px 12px !important;
            margin-bottom: 8px !important;
            text-align: center !important;
        }
        .invoice-title h2 {
            margin: 0 !important;
            font-size: 24px !important;
            text-align: center !important;
            font-weight: bold !important;
            color: #000000 !important;
            letter-spacing: 2px !important;
        }
        .invoice-info {
            padding: 6px 12px !important;
        }
        .invoice-columns {
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            gap: 20px !important;
        }
        .invoice-col {
            flex: 1 !important;
        }
        .customer-info h3 {
            margin: 0 0 6px 0 !important;
            font-size: 12px !important;
            font-weight: bold !important;
            color: #000000 !important;
            text-transform: uppercase !important;
            letter-spacing: 1px !important;
            height: 18px !important;
        }
        .order-info h3 {
            margin: 0 0 6px 0 !important;
            font-size: 12px !important;
            font-weight: bold !important;
            color: #000000 !important;
            text-transform: uppercase !important;
            letter-spacing: 1px !important;
            height: 18px !important;
            text-align: right !important;
        }
        .order-info {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }
        .order-details {
            margin-top: 0 !important;
        }
        .customer-details, .order-details {
            font-size: 11px !important;
            line-height: 1.4 !important;
            color: #000000 !important;
        }
        .order-details {
            text-align: right !important;
        }
        .order-details strong {
            font-weight: bold !important;
            color: #000000 !important;
        }
        .customer-name {
            font-size: 12px !important;
            font-weight: bold !important;
            margin-bottom: 3px !important;
            color: #000000 !important;
        }
        .products-table {
            margin: 6px 12px !important;
            width: calc(100% - 24px) !important;
            margin-bottom: 10px !important;
        }
        .products-table th {
            padding: 10px 8px !important;
            font-size: 11px !important;
            font-weight: bold !important;
            color: #ffffff !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }
        .products-table td {
            padding: 8px 8px !important;
            font-size: 11px !important;
            color: #000000 !important;
        }
        .product-name {
            font-size: 11px !important;
            font-weight: bold !important;
            margin-bottom: 2px !important;
            color: #000000 !important;
        }
        .product-sku {
            font-size: 9px !important;
            color: #666666 !important;
            font-style: italic !important;
        }
        .totals-section {
            padding: 0 12px 10px 12px !important;
        }
        .totals-table {
            max-width: 250px !important;
        }
        .totals-table td {
            padding: 6px 10px !important;
            font-size: 11px !important;
            color: #000000 !important;
        }
        .totals-table .label {
            font-weight: bold !important;
        }
        .totals-table .amount {
            font-weight: bold !important;
        }
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 13px !important;
            font-weight: bold !important;
            color: #000000 !important;
        }
        .footer {
            padding: 10px 12px !important;
            font-size: 9px !important;
            color: #000000 !important;
        }
        .footer-message {
            font-size: 10px !important;
            margin-bottom: 6px !important;
            font-weight: 500 !important;
            color: #000000 !important;
        }
        .footer-contact {
            font-size: 9px !important;
            color: #000000 !important;
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
            color: #2d3748;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo {
            width: 180px;
            height: 120px;
            background-color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .company-info h1 {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
            letter-spacing: 1px;
            color: #2d3748;
        }
        
        .company-tagline {
            font-size: 13px;
            color: #4a5568;
            font-style: italic;
        }
        
        .company-details {
            text-align: right;
            font-size: 12px;
            line-height: 1.4;
            color: #4a5568;
        }
        
        .company-details div {
            margin-bottom: 2px;
        }
        
        .company-details strong {
            color: #2d3748;
        }
        
        .invoice-title {
            background-color: white;
            padding: 12px 24px 10px 24px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .invoice-title h2 {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            text-align: center;
            letter-spacing: 1px;
            margin: 0;
        }
        
        .invoice-info { padding: 30px; }
        .invoice-columns { display: flex; gap: 40px; }
        .invoice-col { flex: 1; }
        
        .customer-info h3,
        .order-info h3 {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .customer-details,
        .order-details {
            font-size: 14px;
            line-height: 1.6;
            color: #4a5568;
            font-weight: 400;
        }
        
        .customer-name {
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 6px;
        }
        
        .order-details strong {
            color: #1a202c;
            font-weight: 600;
        }
        
        /* Fix web view alignment too */
        .invoice-columns {
            align-items: flex-start;
        }
        
        .customer-info h3,
        .order-info h3 {
            margin-top: 0;
            margin-bottom: 12px;
            height: 20px;
            line-height: 20px;
        }
        
        .order-info h3 {
            text-align: right;
        }
        
        .customer-details div,
        .order-details div {
            margin-bottom: 5px;
        }
        
        .customer-name {
            font-weight: bold;
            color: #2d3748;
        }
        
        .products-table {
            margin: 0 30px;
            border-collapse: collapse;
            width: calc(100% - 60px);
            margin-bottom: 30px;
        }
        
        .products-table thead {
            background-color: #2d3748;
            color: white;
        }
        
        .products-table th {
            padding: 16px 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .products-table th:nth-child(2),
        .products-table th:nth-child(3) {
            text-align: center;
        }
        
        .products-table th:last-child {
            text-align: right;
        }
        
        .products-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
        }
        
        .products-table tbody tr:nth-child(even) {
            background-color: #f7fafc;
        }
        
        .products-table td {
            padding: 16px 12px;
            font-size: 14px;
            color: #4a5568;
            font-weight: 400;
        }
        
        .products-table td:nth-child(2),
        .products-table td:nth-child(3) {
            text-align: center;
        }
        
        .products-table td:last-child {
            text-align: right;
            font-weight: 600;
            color: #1a202c;
        }
        
        .product-name {
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 4px;
            font-size: 14px;
        }
        
        .product-sku {
            font-size: 12px;
            color: #718096;
            font-weight: 400;
        }
        
        .totals-section {
            padding: 0 30px 30px 30px;
        }
        
        .totals-table {
            width: 100%;
            max-width: 300px;
            margin-left: auto;
            border-collapse: collapse;
        }
        
        .totals-table td {
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 400;
        }
        
        .totals-table .label {
            text-align: right;
            font-weight: 500;
            color: #4a5568;
            border-right: 1px solid #e2e8f0;
        }
        
        .totals-table .amount {
            text-align: right;
            color: #1a202c;
            font-weight: 600;
        }
        
        .totals-table .total-row {
            border-top: 2px solid #1a202c;
            background-color: #f7fafc;
        }
        
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 16px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .footer {
            background-color: #f7fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-message {
            font-size: 15px;
            color: #4a5568;
            margin-bottom: 16px;
            font-weight: 500;
            font-style: italic;
        }
        
        .footer-contact {
            font-size: 13px;
            color: #718096;
            line-height: 1.6;
            font-weight: 400;
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
                <div>Azizpura Market, G-6/1</div>
                <div>Islamabad</div>
                <div>www.nizamifarms.com</div>
                <div>Ph: 0333-5300605</div>
            </div>
        </div>
        
        <!-- Invoice Title -->
        <div class="invoice-title">
            <h2>INVOICE</h2>
        </div>
        
        <!-- Invoice Information -->
        <div class="invoice-info">
            @if(!empty($isPdf))
                <!-- PDF-friendly table layout -->
                <table class="invoice-two-col">
                    <tr>
                        <td class="invoice-col-left">
                            <div class="invoice-block">
                                <h5 class="title">BILL TO:</h5>
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
                                <h5 class="title">INVOICE DETAILS:</h5>
                                <p><strong>Invoice Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</p>
                                <p><strong>Order Number:</strong> {{ $order->order_number }}</p>
                                <p><strong>Order Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</p>
                            </div>
                        </td>
                    </tr>
                </table>
            @else
                <!-- Web view - keep existing flexbox layout -->
                <div class="invoice-columns">
                <div class="customer-section invoice-col">
                    <div class="customer-info">
                        <h3>Bill To:</h3>
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
                </div>
                <div class="order-section invoice-col">
                    <div class="order-info">
                        <h3>Invoice Details:</h3>
                        <div class="order-details" style="text-align:right;">
                            <div><strong>Invoice Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</div>
                            <div><strong>Order Number:</strong> {{ $order->order_number }}</div>
                            <div><strong>Order Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</div>
                        </div>
                    </div>
                </div>
                </div>
            @endif
        </div>
        
        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th class="col-product">Product</th>
                    <th class="col-qty">Quantity</th>
                    <th class="col-price">Price</th>
                    <th class="col-total">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->lineItems as $item)
                <tr>
                    <td class="col-product">
                        <div class="product-name">{{ $item->name ?: 'N/A' }}</div>
                        @if($item->sku)
                            <div class="product-sku">SKU: {{ $item->sku }}</div>
                        @endif
                    </td>
                    <td class="col-qty">{{ number_format($item->quantity, ($item->quantity == floor($item->quantity)) ? 0 : 3) }}</td>
                    <td class="col-price">Rs.{{ number_format($item->unit_price, 0) }}</td>
                    <td class="col-total">Rs.{{ number_format($item->line_total ?: ($item->quantity * $item->unit_price), 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td class="label">Subtotal:</td>
                    <td class="amount">Rs.{{ number_format($order->lineItems->sum(function($item) { return $item->line_total ?: ($item->quantity * $item->unit_price); }), 0) }}</td>
                </tr>
                @php
                    $discountBreakdown = $order->getDiscountBreakdown();
                @endphp
                @if($discountBreakdown->isNotEmpty())
                    @foreach($discountBreakdown as $discount)
                    <tr>
                        <td class="label">{{ $discount->discount_title }}:</td>
                        <td class="amount">-Rs.{{ number_format($discount->discount_amount, 0) }}</td>
                    </tr>
                    @endforeach
                @endif
                @if($order->shipping_total && $order->shipping_total > 0)
                <tr>
                    <td class="label">Shipping:</td>
                    <td class="amount">Rs.{{ number_format($order->shipping_total, 0) }}</td>
                </tr>
                @endif
                <tr class="total-row">
                    <td class="label">Total:</td>
                    <td class="amount">Rs.{{ number_format($order->total_price, 0) }}</td>
                </tr>
            </table>
        </div>
        
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-message">
                You have trusted us to serve you the best meat in town, thank you!
            </div>
            <div class="footer-contact">
                Follow us on Facebook & Instagram: @nizamifarms, in case of complaints, please contact: 0333-5300605 or write to<br>
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
    const customerName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", trim(($order->customer->first_name ?? "") . " " . ($order->customer->last_name ?? ""))) ?: "Unknown" }}';
    const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
    const orderNumber = '{{ $order->order_number }}';
    const filename = customerName + '_' + phoneNumber + '_' + orderNumber;
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
      const customerName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", trim(($order->customer->first_name ?? "") . " " . ($order->customer->last_name ?? ""))) ?: "Unknown" }}';
      const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
      const orderNumber = '{{ $order->order_number }}';
      link.download = customerName + '_' + phoneNumber + '_' + orderNumber + '.png';
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
      const customerName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", trim(($order->customer->first_name ?? "") . " " . ($order->customer->last_name ?? ""))) ?: "Unknown" }}';
      const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
      const orderNumber = '{{ $order->order_number }}';
      link.download = customerName + '_' + phoneNumber + '_' + orderNumber + '.png';
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
