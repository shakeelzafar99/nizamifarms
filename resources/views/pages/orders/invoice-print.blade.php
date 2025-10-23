<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $filename }}</title>
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
            min-height: 1000px;
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
            padding: 20px 30px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .logo-section {
            flex: 1;
            display: flex;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        
        .logo img {
            height: 80px;
            width: auto;
            object-fit: contain;
            transform: scale(1.4);
            transform-origin: left center;
        }
        
        .company-details {
            flex: 1;
            text-align: right;
            font-size: 12px;
            line-height: 1.4;
            color: #4a5568;
            display: flex;
            flex-direction: column;
            justify-content: center;
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
        
        /* Download Instructions */
        .download-instructions {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #059669;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            max-width: 300px;
        }
        
        .download-instructions h4 {
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .download-instructions p {
            margin-bottom: 8px;
            line-height: 1.4;
        }
        
        .download-instructions kbd {
            background: rgba(255,255,255,0.2);
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        @media print {
            .download-instructions {
                display: none;
            }
        }
    </style>
</head>
<body>
        <!-- Download Instructions -->
        <div class="download-instructions" style="{{ isset($forExport) && $forExport ? 'display:none' : '' }}">
            <h4>📥 Download Invoice</h4>
            <button onclick="downloadAsPDF()" 
                    style="display: inline-block; background: #dc2626; color: white; padding: 10px 18px; border-radius: 6px; border: none; cursor: pointer; margin-bottom: 8px; font-weight: bold; font-size: 14px;">
                📄 Download PDF
            </button>
            <br>
            <button onclick="downloadAsImage()" 
                    style="display: inline-block; background: #059669; color: white; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; margin-bottom: 8px; font-weight: bold;">
                📷 Download PNG Image
            </button>
            <br>
            <button onclick="window.location.href='{{ url()->current() }}?force_pdf=1'" 
                    style="display: inline-block; background: #7c3aed; color: white; padding: 6px 14px; border-radius: 6px; border: none; cursor: pointer; margin-bottom: 8px; font-weight: bold; font-size: 12px;">
                ⚡ Server PDF Download
            </button>
            <br>
            <button onclick="printToPDF()" 
                    style="display: inline-block; background: #0891b2; color: white; padding: 6px 14px; border-radius: 6px; border: none; cursor: pointer; margin-bottom: 10px; font-weight: bold; font-size: 12px;">
                🖨️ Print to PDF
            </button>
            <p><strong>Alternative:</strong> Right-click on the invoice → "Save image as..."</p>
            <p>Filename: <strong>{{ $filename }}</strong></p>
        </div>

    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="logo-section">
                <div class="logo">
                    @php
                        // Try to find logo with base64 encoding for reliable display
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

    <script>
        // Auto-download PDF function that mimics Ctrl+P behavior with automatic download
        function downloadAsPDF() {
            // Show loading state
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '⏳ Generating PDF...';
            button.disabled = true;
            
            // Create a hidden link to trigger download
            const link = document.createElement('a');
            link.href = '{{ url()->current() }}?force_pdf=1';
            link.style.display = 'none';
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Reset button after a delay
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 2000);
        }
        
        // Download as image function
        function downloadAsImage() {
            // Show loading state
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '⏳ Generating Image...';
            button.disabled = true;
            
            // Create a hidden link to trigger download
            const link = document.createElement('a');
            link.href = '{{ url()->current() }}?download_image=1';
            link.style.display = 'none';
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Reset button after a delay
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 3000); // Longer delay for image generation
        }
        
        // Print to PDF using browser's print dialog
        function printToPDF() {
            // Hide download instructions for clean print
            const instructions = document.querySelector('.download-instructions');
            if (instructions) {
                instructions.style.display = 'none';
            }
            
            // Set document title for PDF filename
            const originalTitle = document.title;
            document.title = '{{ $filename }}';
            
            // Add print-specific CSS
            const printCSS = `
                @media print {
                    .download-instructions { display: none !important; }
                    @page { margin: 0.5in; size: A4; }
                    body { -webkit-print-color-adjust: exact !important; }
                }
            `;
            const style = document.createElement('style');
            style.textContent = printCSS;
            document.head.appendChild(style);
            
            // Trigger print dialog
            window.print();
            
            // Restore after print
            setTimeout(() => {
                document.title = originalTitle;
                if (instructions) instructions.style.display = 'block';
                document.head.removeChild(style);
            }, 1000);
        }
        
        // Auto-trigger PDF download if requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_pdf') === '1') {
            // Wait for page to fully load, then trigger PDF download
            window.addEventListener('load', function() {
                setTimeout(() => {
                    // Try to use the browser's built-in PDF generation if available
                    if (window.chrome && window.chrome.runtime) {
                        // Chrome/Edge specific approach
                        downloadAsPDF();
                    } else {
                        // For other browsers, show a helpful message and auto-trigger print
                        showAutoDownloadMessage();
                        setTimeout(downloadAsPDF, 1000);
                    }
                }, 500);
            });
        }
        
        function showAutoDownloadMessage() {
            // Create a temporary message overlay
            const messageDiv = document.createElement('div');
            messageDiv.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #059669;
                color: white;
                padding: 20px 30px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: bold;
                z-index: 10000;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                text-align: center;
            `;
            messageDiv.innerHTML = `
                🎯 Auto-downloading PDF...<br>
                <small style="font-weight: normal; margin-top: 8px; display: block;">
                In the print dialog, select "Save as PDF" and choose your Downloads folder.<br>
                Filename will be: <strong>{{ $filename }}.pdf</strong>
                </small>
            `;
            document.body.appendChild(messageDiv);
            
            // Remove message after 4 seconds
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    document.body.removeChild(messageDiv);
                }
            }, 4000);
        }
        
        // Keyboard shortcut support
        document.addEventListener('keydown', function(e) {
            // Ctrl+P or Cmd+P
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                downloadAsPDF();
            }
            // Ctrl+S or Cmd+S for image download
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                downloadAsImage();
            }
        });
        
        // Show user-friendly message
        console.log('📄 Press Ctrl+P to download PDF | 📷 Press Ctrl+S to download image');
    </script>
</body>
</html>
