{{-- ============================================================================
     Vendor report — the ONE renderer. Aug-2026.

     Both surfaces that print a vendor report now call this:
       • the old vendor page   (fin/vendor/show.blade.php)     → prints the page itself
       • the Ledger Hub vendor (fin/hub/partials/vendor-op-modals.blade.php)
                                                               → prints a popup window
     They already shared the DATA (`/finance/vendors/report`); they did NOT share the
     rendering, so the hub had grown its own much thinner version. One function now,
     so "the hub looks exactly like the old page" stays true after the next edit.

     ⭐⭐ The returned HTML is SELF-CONTAINED — it carries its own <style>, scoped
     under `.nfvr`, and uses NO Tailwind. That is the whole reason the hub's print
     came out unstyled: it writes the markup into a blank popup window that has none
     of the host page's CSS. Every colour below is the literal value of the Tailwind
     class the old page used, so the printed output is unchanged.

     ⚠ Do not put Blade echoes in here, and never write the verbatim directive's name
     with its leading at-sign in this comment: the block below is wrapped in one so the
     CSS at-rules reach the browser instead of being read as Blade directives, and Blade
     finds the OPENING marker by plain text search — a mention up here is matched first
     and swallows the rest of this comment into the raw block, which then prints on the
     page. (That happened while writing this file.)
     ============================================================================ --}}
@verbatim
<script>
(function(){
    if (window.nfVendorReportHtml) return;   // included by both pages; define once

    function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    // Two money formats, kept distinct because the original used both: amounts are
    // pinned to exactly 2 decimals, rates/subtotals only set a MINIMUM of 2 (so a
    // rate of 1234.567 still prints in full rather than being silently rounded).
    function money2(n){ return Number(n||0).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function moneyMin2(n){ return Number(n||0).toLocaleString('en-PK', {minimumFractionDigits:2}); }
    function qty3(n){ return Number(n||0).toFixed(3); }

    // Group a purchase's line items by product so repeated weighings of the same
    // product collapse into one subtotal, and sort by product name.
    function groupAndSortByProduct(lineItems){
        var grouped = {};
        lineItems.forEach(function(item){
            var key = item.product_name;
            if(!grouped[key]){
                grouped[key] = { product_name:item.product_name, unit:item.unit,
                                 rate_per_unit:item.rate_per_unit, items:[],
                                 total_quantity:0, total_amount:0 };
            }
            grouped[key].items.push(item);
            grouped[key].total_quantity += parseFloat(item.quantity);
            grouped[key].total_amount += parseFloat(item.line_total || (item.quantity * item.rate_per_unit));
        });
        return Object.values(grouped).sort(function(a,b){ return a.product_name.localeCompare(b.product_name); });
    }

    var STYLE = ''
      + '<style>'
      + '.nfvr{background:#fff;color:#374151;font-size:14px;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      + '.nfvr .vr-head{text-align:center;margin-bottom:20px;padding:20px 0;border-bottom:3px solid #7c3aed}'
      + '.nfvr .vr-h1{font-size:28px;font-weight:bold;color:#111827;margin:0 0 8px 0}'
      + '.nfvr .vr-period{font-size:14px;color:#6b7280;margin:0}'
      + '.nfvr .vr-body{padding:0 20px}'
      + '.nfvr .vr-scroll{overflow-x:auto}'
      + '.nfvr table{width:100%;border-collapse:collapse;font-size:14px}'
      + '.nfvr th,.nfvr td{border:1px solid #d1d5db;padding:8px;vertical-align:middle}'
      + '.nfvr thead th{background:#f3e8ff;text-align:left;font-weight:600;color:#374151}'
      + '.nfvr .r{text-align:right}.nfvr .top{vertical-align:top}.nfvr .b{font-weight:700}.nfvr .m{font-weight:500}'
      + '.nfvr .xs{font-size:12px}'
      + '.nfvr .row-pur{background:#fef2f2}.nfvr .row-pay{background:#f0fdf4}'
      + '.nfvr .c-pur{color:#b91c1c}.nfvr .c-pay{color:#15803d}'
      + '.nfvr .c-red{color:#dc2626}.nfvr .c-green{color:#16a34a}.nfvr .c-blue{color:#2563eb}'
      + '.nfvr .c-orange{color:#ea580c}.nfvr .c-orange-d{color:#c2410c}.nfvr .c-gray{color:#6b7280}'
      + '.nfvr .c-ink{color:#111827}.nfvr .c-ink2{color:#1f2937}.nfvr .c-ink3{color:#374151}'
      + '.nfvr .sub-row{background:linear-gradient(90deg,#dbeafe 0%,#eff6ff 100%)}'
      + '.nfvr .sub-row td{padding:4px 8px}'
      + '.nfvr .sub-l{font-size:12px;color:#2563eb;font-weight:500}'
      + '.nfvr .sub-v{font-size:12px;color:#1d4ed8;font-weight:700;text-align:right}'
      + '.nfvr .tot-row td{background:#f3e8ff;font-weight:700}'
      + '.nfvr .ml2{margin-left:8px}.nfvr .ml4{margin-left:16px}'
      + '.nfvr .prod-box{margin-top:24px;padding:16px;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);'
      +   'border:2px solid #f59e0b;border-radius:8px}'
      + '.nfvr .prod-box h3{font-size:16px;font-weight:bold;color:#92400e;margin:0 0 12px 0}'
      + '.nfvr .prod-box th{background:rgba(245,158,11,.2);color:#92400e;font-weight:600;border:0;'
      +   'border-bottom:1px solid #f59e0b;padding:8px 12px}'
      + '.nfvr .prod-box td{border:0;border-bottom:1px solid rgba(245,158,11,.3);padding:8px 12px;color:#78350f}'
      + '.nfvr .prod-box .grand td{background:rgba(245,158,11,.3);color:#92400e;font-weight:bold;padding:10px 12px}'
      + '.nfvr .prod-box .net td{background:rgba(245,158,11,.4);color:#92400e;font-weight:bold;padding:10px 12px}'
      + '.nfvr .prod-box .adjr td{background:rgba(245,158,11,.2)}'
      // Print: the old page forced black table borders, so match it exactly, and keep
      // every background/colour (a stripped report is unreadable in mono).
      + '@media print{'
      +   '.nfvr table,.nfvr th,.nfvr td{border:1px solid #000}'
      +   '.nfvr,.nfvr *{-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      +   '.nfvr .vr-body{padding:0}'
      + '}'
      + '</style>';

    /**
     * Build the vendor report markup.
     *   report  — the payload from /finance/vendors/report (report.vendors[0] is used)
     *   opts.vendorName   — heading
     *   opts.showPayments — mirrors the "Show payments" checkbox (footer detail only;
     *                       the server already omits payment rows when it is off)
     *   opts.rootId       — optional id for the root div. The old page's print CSS
     *                       keys off #printableVendorReport, so it passes that.
     * Returns '' when the range has no activity — the caller decides the empty state.
     */
    window.nfVendorReportHtml = function(report, opts){
        opts = opts || {};
        var vendor = (report && report.vendors && report.vendors.length) ? report.vendors[0] : null;
        if(!vendor) return '';

        var showPayments = opts.showPayments !== false;
        var overallProductTotals = {};
        var totalAdjustments = 0;

        var html = STYLE
          + '<div class="nfvr"' + (opts.rootId ? ' id="' + esc(opts.rootId) + '"' : '') + '>'
          + '<div class="vr-head">'
          +   '<h1 class="vr-h1">' + esc(opts.vendorName || vendor.vendor_name || '') + '</h1>'
          +   '<p class="vr-period">Report Period: ' + esc(report.date_from) + ' to ' + esc(report.date_to) + '</p>'
          + '</div>'
          + '<div class="vr-body"><div class="vr-scroll"><table><thead><tr>'
          +   '<th style="width:120px">Date</th><th style="width:100px">Type</th><th>Product</th>'
          +   '<th class="r" style="width:80px">Qty</th><th class="r" style="width:90px">Rate</th>'
          +   '<th class="r" style="width:110px">Amount</th>'
          + '</tr></thead><tbody>';

        (vendor.daily_summary || []).forEach(function(day){
            // How many rows the Date cell must span: every line item, one subtotal row
            // per product group, plus an adjustment row where there is one.
            var dayRowSpan = 0;
            (day.transactions || []).forEach(function(txn){
                if(txn.line_items && txn.line_items.length > 0){
                    var hasAdj = txn.adjustment_amount && parseFloat(txn.adjustment_amount) !== 0;
                    var rowCount = 0;
                    groupAndSortByProduct(txn.line_items).forEach(function(g){ rowCount += g.items.length + 1; });
                    dayRowSpan += rowCount + (hasAdj ? 1 : 0);
                } else {
                    dayRowSpan += 1;
                }
            });

            var isFirstRowOfDay = true;

            (day.transactions || []).forEach(function(txn){
                var isPayment = txn.type === 'payment';
                var amtCls = isPayment ? 'c-pay' : 'c-pur';
                var rowCls = isPayment ? 'row-pay' : 'row-pur';
                var adjustmentAmount = parseFloat(txn.adjustment_amount || 0);
                var hasAdjustment = adjustmentAmount !== 0;
                if(hasAdjustment) totalAdjustments += adjustmentAmount;

                // The transaction reference is genuinely null on hand-entered rows —
                // print nothing rather than the word "null" (what the old page did).
                var ref = (txn.transaction_id == null || txn.transaction_id === '')
                    ? '' : '<div class="xs c-gray" style="margin-top:2px">' + esc(txn.transaction_id) + '</div>';

                if(txn.line_items && txn.line_items.length > 0){
                    var productGroups = groupAndSortByProduct(txn.line_items);

                    productGroups.forEach(function(group){
                        var key = group.product_name;
                        if(!overallProductTotals[key]){
                            overallProductTotals[key] = { product_name:group.product_name, unit:group.unit,
                                                          total_quantity:0, total_amount:0 };
                        }
                        overallProductTotals[key].total_quantity += group.total_quantity;
                        overallProductTotals[key].total_amount += group.total_amount;
                    });

                    var totalRowsForThisTxn = 0;
                    productGroups.forEach(function(g){ totalRowsForThisTxn += g.items.length + 1; });
                    totalRowsForThisTxn += hasAdjustment ? 1 : 0;

                    var isFirstItemOfTxn = true;

                    productGroups.forEach(function(group, groupIndex){
                        group.items.forEach(function(item, itemIndex){
                            html += '<tr class="' + rowCls + '">';
                            if(isFirstRowOfDay){
                                html += '<td class="top m c-ink" rowspan="' + dayRowSpan + '">' + esc(day.date) + '</td>';
                                isFirstRowOfDay = false;
                            }
                            if(isFirstItemOfTxn){
                                html += '<td class="top" rowspan="' + totalRowsForThisTxn + '">'
                                     +    '<div class="m ' + amtCls + '">📦 Purchase</div>' + ref
                                     +  '</td>';
                                isFirstItemOfTxn = false;
                            }
                            html += '<td class="c-ink2">' + esc(item.product_name) + '</td>'
                                 +  '<td class="r c-ink3">' + qty3(item.quantity) + ' ' + esc(item.unit) + '</td>'
                                 +  '<td class="r c-ink3">Rs. ' + moneyMin2(item.rate_per_unit) + '</td>';
                            if(groupIndex === 0 && itemIndex === 0){
                                html += '<td class="r top b ' + amtCls + '" rowspan="' + totalRowsForThisTxn + '">'
                                     +    'Rs. ' + money2(txn.amount)
                                     +  '</td>';
                            }
                            html += '</tr>';
                        });

                        // Per-product subtotal. Only three cells — Date, Type and Amount
                        // are covered by the rowspans above.
                        html += '<tr class="sub-row">'
                             +    '<td class="sub-l">↳ Subtotal</td>'
                             +    '<td class="sub-v">' + qty3(group.total_quantity) + ' ' + esc(group.unit) + '</td>'
                             +    '<td class="sub-v">Rs. ' + moneyMin2(group.total_amount) + '</td>'
                             +  '</tr>';
                    });

                    if(hasAdjustment){
                        var adjPrefix = adjustmentAmount > 0 ? '+' : '';
                        var adjCls = adjustmentAmount < 0 ? 'c-pay' : 'c-orange-d';
                        var adjBg = adjustmentAmount < 0 ? '#d1fae5' : '#ffedd5';
                        html += '<tr style="background:' + adjBg + '">'
                             +    '<td class="m ' + adjCls + '" colspan="2">📊 Adjustment/Discount</td>'
                             +    '<td class="r b ' + adjCls + '">' + adjPrefix + 'Rs. ' + moneyMin2(adjustmentAmount) + '</td>'
                             +  '</tr>';
                    }
                } else {
                    // Flat purchase, or a payment.
                    html += '<tr class="' + rowCls + '">';
                    if(isFirstRowOfDay){
                        html += '<td class="top m c-ink" rowspan="' + dayRowSpan + '">' + esc(day.date) + '</td>';
                        isFirstRowOfDay = false;
                    }
                    html += '<td>'
                         +    '<div class="m ' + amtCls + '">' + (isPayment ? '💰 Payment' : '📦 Purchase') + '</div>'
                         +    ref
                         +    ((isPayment && txn.payment_mode) ? '<div class="xs c-blue" style="margin-top:4px">' + esc(txn.payment_mode) + '</div>' : '')
                         +  '</td>'
                         +  '<td class="c-ink3" colspan="3">' + (txn.description ? esc(txn.description) : '-') + '</td>'
                         +  '<td class="r b ' + amtCls + '">Rs. ' + money2(txn.amount) + '</td>'
                         +  '</tr>';
                }
            });
        });

        var paymentsOnline = 0, paymentsCash = 0;
        (vendor.daily_summary || []).forEach(function(d){
            paymentsOnline += d.total_payments_online || 0;
            paymentsCash += d.total_payments_cash || 0;
        });

        var adjustmentDisplay = totalAdjustments !== 0
            ? '<span class="xs ml2 ' + (totalAdjustments < 0 ? 'c-green' : 'c-orange') + '">(Adj: '
              + (totalAdjustments > 0 ? '+' : '') + 'Rs. ' + moneyMin2(totalAdjustments) + ')</span>'
            : '';

        var net = Number(vendor.total_purchases) - Number(vendor.total_payments);

        html += '<tr class="tot-row">'
             +    '<td colspan="5" class="r">'
             +      '<span class="c-ink3">Vendor Total:</span>'
             +      '<span class="c-red ml4">Purchases: Rs. ' + moneyMin2(vendor.total_purchases) + '</span>'
             +      adjustmentDisplay
             +      (showPayments
                      ? '<span class="c-green ml4">Payments: Rs. ' + moneyMin2(vendor.total_payments) + '</span>'
                        + (paymentsOnline > 0 ? '<span class="xs c-blue ml2">(Online: Rs. ' + moneyMin2(paymentsOnline) + ')</span>' : '')
                        + (paymentsCash > 0 ? '<span class="xs c-orange ml2">(Cash: Rs. ' + moneyMin2(paymentsCash) + ')</span>' : '')
                      : '')
             +    '</td>'
             +    '<td class="r ' + (net > 0 ? 'c-red' : 'c-green') + '">Rs. ' + money2(net) + '</td>'
             +  '</tr>'
             +  '</tbody></table></div>';

        // Overall product-wise summary — only meaningful for by-weight vendors, so it
        // disappears entirely when nothing in the range had line items.
        var productTotals = Object.values(overallProductTotals);
        if(productTotals.length > 0){
            html += '<div class="prod-box"><h3>📦 Overall Product-wise Summary</h3>'
                 +  '<table><thead><tr><th>Product</th><th class="r">Total Qty</th><th class="r">Total Amount</th></tr></thead><tbody>';
            var grandTotalAmount = 0;
            productTotals.forEach(function(p){
                grandTotalAmount += p.total_amount;
                html += '<tr><td class="m">' + esc(p.product_name) + '</td>'
                     +  '<td class="r b">' + qty3(p.total_quantity) + ' ' + esc(p.unit) + '</td>'
                     +  '<td class="r b">Rs. ' + moneyMin2(p.total_amount) + '</td></tr>';
            });
            html += '<tr class="grand"><td>GRAND TOTAL</td><td class="r">-</td>'
                 +  '<td class="r">Rs. ' + moneyMin2(grandTotalAmount) + '</td></tr>';
            if(totalAdjustments !== 0){
                html += '<tr class="adjr"><td class="m ' + (totalAdjustments < 0 ? 'c-green' : 'c-orange') + '">Total Adjustments</td>'
                     +  '<td class="r">-</td>'
                     +  '<td class="r b ' + (totalAdjustments < 0 ? 'c-green' : 'c-orange') + '">'
                     +    (totalAdjustments > 0 ? '+' : '') + 'Rs. ' + moneyMin2(totalAdjustments) + '</td></tr>'
                     +  '<tr class="net"><td>NET TOTAL (After Adjustments)</td><td class="r">-</td>'
                     +  '<td class="r">Rs. ' + moneyMin2(grandTotalAmount + totalAdjustments) + '</td></tr>';
            }
            html += '</tbody></table></div>';
        }

        return html + '</div></div>';
    };

    /**
     * Print the report in its own window. Used where there is no page-level print
     * stylesheet to lean on (the Ledger Hub modal). The markup carries its own CSS,
     * so the popup needs nothing but page setup — this is exactly what was missing
     * before: the old popup wrote Tailwind-classed markup into a document with no
     * Tailwind, which is why it printed as plain text.
     */
    window.nfVendorReportPrint = function(html, title){
        var w = window.open('', '_blank');
        if(!w){ alert('Your browser blocked the print window. Allow pop-ups for this site and try again.'); return; }
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title || 'Vendor report') + '</title>'
            + '<style>@page{margin:0.5cm;size:A4}'
            + 'html,body{margin:0;padding:0}'
            + 'body{font-family:Arial,Helvetica,sans-serif;padding:10px;'
            + '-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            + '.nfvr-foot{margin-top:20px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:10pt;color:#666}'
            + '</style></head><body>' + html
            + '<div class="nfvr-foot">Nizami Farms</div></body></html>');
        w.document.close();
        w.focus();
        // The window needs a beat to lay the table out; printing immediately can
        // produce a blank first page.
        setTimeout(function(){ w.print(); }, 300);
    };
})();
</script>
@endverbatim
