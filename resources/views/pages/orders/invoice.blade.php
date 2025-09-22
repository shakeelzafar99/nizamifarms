<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $order->order_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        @page { margin: 3mm 8mm 8mm 8mm; }
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        /* PDF-specific complete override */
        @if(!empty($isPdf))
        body {
            background: #ffffff !important;
            padding: 0 !important;
            margin: 0 !important;
            font-family: 'DejaVu Sans', Arial, sans-serif !important;
        }
        .invoice-container {
            box-shadow: none !important;
            max-width: 100% !important;
            margin: 0 !important;
        }
        .invoice-header {
            padding: 4px 12px !important;
            margin-bottom: 4px !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
        }
        .logo-section {
            flex: 1 !important;
            display: flex !important;
            align-items: flex-start !important;
        }
        .logo {
            width: auto !important;
            height: auto !important;
            padding: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            background: none !important;
        }
        .logo img {
            height: 40px !important;
            width: auto !important;
            display: block !important;
        }
        .company-details {
            flex: 1 !important;
            text-align: right !important;
            font-size: 10px !important;
            line-height: 1.2 !important;
            margin-top: 0 !important;
            align-self: flex-start !important;
        }
        .invoice-title {
            padding: 4px 12px !important;
            margin-bottom: 8px !important;
            text-align: center !important;
        }
        .invoice-title h2 {
            margin: 0 !important;
            font-size: 20px !important;
            text-align: center !important;
        }
        .invoice-info {
            padding: 8px 12px !important;
        }
        .invoice-columns {
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
        }
        .customer-info h3, .order-info h3 {
            margin: 0 0 6px 0 !important;
            font-size: 12px !important;
        }
        .customer-details, .order-details {
            font-size: 11px !important;
            line-height: 1.4 !important;
        }
        .order-details {
            text-align: right !important;
        }
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
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            text-align: center;
        }
        
        .invoice-info { padding: 30px; }
        .invoice-columns { display: flex; gap: 40px; }
        .invoice-col { flex: 1; }
        
        .customer-info h3,
        .order-info h3 {
            font-size: 14px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .customer-details,
        .order-details {
            font-size: 14px;
            line-height: 1.6;
            color: #4a5568;
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
            padding: 15px 10px;
            text-align: left;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
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
            padding: 15px 10px;
            font-size: 13px;
            color: #4a5568;
        }
        
        .products-table td:nth-child(2),
        .products-table td:nth-child(3) {
            text-align: center;
        }
        
        .products-table td:last-child {
            text-align: right;
            font-weight: bold;
        }
        
        .product-name {
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 3px;
        }
        
        .product-sku {
            font-size: 11px;
            color: #718096;
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
            padding: 8px 15px;
            font-size: 14px;
        }
        
        .totals-table .label {
            text-align: right;
            font-weight: bold;
            color: #4a5568;
            border-right: 1px solid #e2e8f0;
        }
        
        .totals-table .amount {
            text-align: right;
            color: #2d3748;
            font-weight: bold;
        }
        
        .totals-table .total-row {
            border-top: 2px solid #2d3748;
            background-color: #f7fafc;
        }
        
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 16px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .footer {
            background-color: #f7fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-message {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 15px;
        }
        
        .footer-contact {
            font-size: 12px;
            color: #718096;
            line-height: 1.5;
        }
        
        @media print {
            body {
                padding: 0;
                background-color: white;
            }
            
            .invoice-container {
                box-shadow: none;
                max-width: none;
            }
        }
    </style>
</head>
<body>
@php $autoPrint = request('print_pdf') == '1'; $autoPng = request('auto_png') == '1'; @endphp
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
                    @if(!empty($isPdf) && $logoDataUri)
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
                    <h3>&nbsp;</h3> <!-- Empty header to align with "Bill To:" -->
                    <div class="order-details" style="text-align:right;">
                        <div><strong>Invoice Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</div>
                        <div><strong>Order Number:</strong> {{ $order->order_number }}</div>
                        <div><strong>Order Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</div>
                    </div>
                </div>
            </div>
            </div>
        </div>
        
        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->lineItems as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->name ?: 'N/A' }}</div>
                        @if($item->sku)
                            <div class="product-sku">SKU: {{ $item->sku }}</div>
                        @endif
                    </td>
                    <td>{{ number_format($item->quantity, ($item->quantity == floor($item->quantity)) ? 0 : 3) }}</td>
                    <td>Rs.{{ number_format($item->unit_price, 0) }}</td>
                    <td>Rs.{{ number_format($item->line_total ?: ($item->quantity * $item->unit_price), 0) }}</td>
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
                @if($order->discount_total && $order->discount_total > 0)
                <tr>
                    <td class="label">Discount:</td>
                    <td class="amount">-Rs.{{ number_format($order->discount_total, 0) }}</td>
                </tr>
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
    // Hide scrollbars and trigger print; user can choose Save as PDF
    document.title = 'Invoice-{{ $order->order_number }}';
    setTimeout(() => { window.print(); }, 300);
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
      link.download = 'Invoice-{{ $order->order_number }}.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      // Close the helper tab/window automatically
      setTimeout(() => { window.close(); }, 500);
    })();
  }
})();
</script>
</body>
</html>
