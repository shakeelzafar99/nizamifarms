<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', 'Arial', sans-serif;
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
            align-items: center;
            padding: 30px 40px 20px 40px;
            border-bottom: 1px solid #e5e7eb;
            min-height: 160px;
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
            height: 160px;
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
        
        /* Invoice Title */
        .invoice-title {
            text-align: center;
            padding: 20px 40px;
        }
        
        .invoice-title h2 {
            font-size: 36px;
            font-weight: 700;
            color: #111827;
            margin: 0;
            letter-spacing: 2px;
        }
        
        /* Invoice Info Section */
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
        
        /* Products Table */
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
        
        /* Totals Section */
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
        
        /* Footer */
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
                            <div style="color: #059669; font-size: 18px; font-weight: bold; margin-bottom: 4px;">NIZAMI FARMS</div>
                            <div style="color: #6b7280; font-size: 11px;">Where quality meat's expectation</div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="company-details">
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
        <div class="invoice-info">
            <div class="customer-section">
                <h3>Customer Details</h3>
                <div class="customer-details">
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
                    <div class="customer-name">{{ $custName }}</div>
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
        
        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 55%;">PRODUCTS</th>
                    <th class="text-center" style="width: 12%;">Qty</th>
                    <th class="text-center" style="width: 15%;">Unit Price</th>
                    <th class="text-right" style="width: 18%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->lineItems as $item)
                @php $hasItemNote = !empty(trim((string)($item->instructions ?? ''))); @endphp
                <tr{!! $hasItemNote ? ' style="background-color: #fefce8;"' : '' !!}>
                    <td>
                        <div class="product-name">{{ $item->name ?: 'N/A' }}@if($item->is_free) <span style="display: inline-block; padding: 1px 5px; background: #dcfce7; color: #16a34a; border-radius: 3px; font-size: 9px; font-weight: 700; margin-left: 3px;">FREE</span>@endif</div>
                        @if(!empty($qurbaniInvoiceFields ?? []))
                        @php
                            $attrParts = [];
                            $fieldMap = ['qurbani_day' => 'Day', 'qurbani_delivery_type' => 'Type', 'qurbani_slot' => 'Slot', 'qurbani_region' => 'Region', 'qurbani_sub_region' => 'Sub Region'];
                            foreach ($qurbaniInvoiceFields as $f) {
                                $val = $item->{$f} ?? null;
                                if ($val) $attrParts[] = ($fieldMap[$f] ?? $f) . ': ' . $val;
                            }
                        @endphp
                        @if(count($attrParts) > 0)
                        <div style="font-size: 11px; color: #92400e; margin-top: 4px; font-weight: 500; line-height: 1.5;">
                            @foreach($attrParts as $attrPart)
                                <div>{{ $attrPart }}</div>
                            @endforeach
                        </div>
                        @endif
                        @endif
                        @if($hasItemNote)
                        <div style="font-size: 10px; color: #92400e; margin-top: 2px; font-style: italic;">📝 {{ $item->instructions }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format($item->quantity, ($item->quantity == floor($item->quantity)) ? 0 : 3) }}</td>
                    <td class="text-center">@if($item->is_free)<s style="color:#aaa;">Rs {{ number_format($item->unit_price, 0) }}</s>@else Rs {{ number_format($item->unit_price, 0) }}@endif</td>
                    <td class="text-right" style="font-weight: 600;">@if($item->is_free)<span style="color: #16a34a; font-weight: 700;">FREE</span>@else Rs {{ number_format($item->line_total ?: ($item->quantity * $item->unit_price), 0) }}@endif</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Total Item Number -->
        <div class="total-items-row">
            <strong>TOTAL ITEM NUMBER:</strong> {{ $order->lineItems->count() }}
        </div>

        @if(!empty(trim((string)($order->note ?? ''))))
        <div style="margin: 6px 0; padding: 6px 10px; background: #fefce8; border: 1px solid #fde68a; border-radius: 4px;">
            <div style="font-size: 11px; color: #92400e; font-weight: 600;">📝 Order Notes:</div>
            <div style="font-size: 11px; color: #78350f; margin-top: 2px;">{{ $order->note }}</div>
        </div>
        @endif
        
        <!-- Totals + PAID stamp (stamp renders only when fully paid) -->
        @php $__paidStampData = $order->getPaidStampData(); @endphp
        <div class="totals-section" style="position:relative;">
            @if ($__paidStampData['show'])
                <div class="paid-stamp-wrap" style="float:left; padding-left:40px; padding-top:10px; max-width:45%;">
                    @include('pages.orders.partials.paid-stamp', ['order' => $order, 'paidStamp' => $__paidStampData])
                </div>
            @endif
            <table class="totals-table">
                @php
                    $discountBreakdown = $order->getDiscountBreakdown();
                    $totalDiscounts = $discountBreakdown->sum('discount_amount');
                    $subtotal = $order->lineItems->sum(function($item) { return $item->is_free ? 0 : ($item->line_total ?: ($item->quantity * $item->unit_price)); });
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
            <div style="clear:both;"></div>
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
</body>
</html>
