<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            background-color: #ffffff;
            padding: 0;
            margin: 0;
            width: 800px;
            min-height: 1200px;
        }
        
        .invoice-container {
            width: 800px;
            background-color: white;
            margin: 0;
            padding: 0;
        }
        
        /* Header Section */
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 30px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .logo-section {
            flex: 1;
            display: flex;
            align-items: flex-start;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        
        .logo img {
            height: 60px;
            width: auto;
            object-fit: contain;
        }
        
        .company-details {
            flex: 1;
            text-align: right;
            font-size: 12px;
            line-height: 1.4;
            color: #4a5568;
        }
        
        .company-details div {
            margin-bottom: 3px;
        }
        
        .company-details strong {
            color: #2d3748;
            font-weight: bold;
        }
        
        /* Invoice Title */
        .invoice-title {
            text-align: center;
            padding: 15px 30px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .invoice-title h2 {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
            font-family: 'Times New Roman', serif;
        }
        
        /* Invoice Info Section */
        .invoice-info {
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .customer-section, .order-section {
            flex: 1;
        }
        
        .customer-section h3, .order-section h3 {
            font-size: 16px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 12px;
            font-family: 'Times New Roman', serif;
        }
        
        .customer-details, .order-details {
            font-size: 14px;
            line-height: 1.6;
            color: #4a5568;
            font-family: 'Times New Roman', serif;
        }
        
        .customer-details div, .order-details div {
            margin-bottom: 6px;
        }
        
        .customer-name {
            font-weight: bold;
            color: #2d3748;
        }
        
        .order-section {
            text-align: right;
        }
        
        /* Products Table */
        .products-table {
            width: calc(100% - 60px);
            margin: 0 30px 30px 30px;
            border-collapse: collapse;
        }
        
        .products-table thead {
            background-color: #2d3748;
            color: white;
        }
        
        .products-table th {
            padding: 15px 12px;
            text-align: left;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            font-family: 'Times New Roman', serif;
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
            padding: 15px 12px;
            font-size: 14px;
            color: #4a5568;
            font-family: 'Times New Roman', serif;
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
            margin-bottom: 4px;
        }
        
        .product-sku {
            font-size: 12px;
            color: #718096;
        }
        
        /* Totals Section */
        .totals-section {
            padding: 0 30px 30px 30px;
        }
        
        .totals-table {
            width: 100%;
            max-width: 350px;
            margin-left: auto;
            border-collapse: collapse;
        }
        
        .totals-table td {
            padding: 10px 20px;
            font-size: 15px;
            font-family: 'Times New Roman', serif;
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
            border-top: 3px solid #2d3748;
            background-color: #f7fafc;
        }
        
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }
        
        /* Footer */
        .footer {
            background-color: #f7fafc;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-message {
            font-size: 15px;
            color: #4a5568;
            margin-bottom: 12px;
            font-family: 'Times New Roman', serif;
        }
        
        .footer-contact {
            font-size: 13px;
            color: #718096;
            line-height: 1.5;
            font-family: 'Times New Roman', serif;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="logo-section">
                <div class="logo">
                    @php
                        // Try to find logo with base64 encoding for reliable image generation
                        $logoPath = public_path('assets/media/logos/nizami-farms-logo.png');
                        if (!file_exists($logoPath)) {
                            $logoPath = public_path('assets/media/logos/nizami-farms-logo.jpg');
                        }
                        if (!file_exists($logoPath)) {
                            $logoPath = public_path('assets/media/logos/nizami-farms-logo.png.jpg');
                        }
                        
                        $logoDataUri = null;
                        if (file_exists($logoPath)) {
                            $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
                            $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
                        }
                    @endphp
                    
                    @if($logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="Nizami Farms">
                    @else
                        <div style="text-align: center; padding: 10px;">
                            <div style="color: #059669; font-size: 20px; font-weight: bold; margin-bottom: 4px; font-family: 'Times New Roman', serif;">NIZAMI FARMS</div>
                            <div style="color: #6b7280; font-size: 12px; font-family: 'Times New Roman', serif;">Where quality meat's expectation</div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="company-details">
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
            <div class="customer-section">
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
            <div class="order-section">
                <h3>&nbsp;</h3>
                <div class="order-details">
                    <div><strong>Invoice Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</div>
                    <div><strong>Order Number:</strong> {{ $order->order_number }}</div>
                    <div><strong>Order Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</div>
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
</body>
</html>
