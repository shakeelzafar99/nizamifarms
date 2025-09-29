<!-- Shared Import Products Modal (reused on Products and Operations pages) -->
@php
    // Avoid duplicate modal IDs if this partial and original modal coexist
    $modalId = 'importProductsModal';
@endphp
<div id="{{ $modalId }}" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="display: flex; min-height: 100%; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: white; border-radius: 8px; width: 100%; max-width: 500px; position: relative;">
            <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
                <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;">Import Products</h3>
            </div>
            <div id="importProductsContent" style="padding: 24px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Import Source</label>
                    <select id="importSource" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateImportOptions()">
                        <option value="shopify">Shopify Store</option>
                        <option value="woocommerce">WooCommerce Store</option>
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Import Type</label>
                    <select id="importType" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateImportOptions()">
                        <option value="products">All Products</option>
                        <option value="inventory">Inventory Only</option>
                    </select>
                </div>
                <div style="background-color: #f3f4f6; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                    <h4 style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 8px 0;">What will be imported:</h4>
                    <ul style="font-size: 12px; color: #6b7280; margin: 0; padding-left: 16px;">
                        <li>Products, variants, images, and attributes</li>
                        <li>Inventory and pricing (based on selected type)</li>
                    </ul>
                </div>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px;">
                <button onclick="closeModal('{{ $modalId }}')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; font-size: 14px; cursor: pointer;">Cancel</button>
                <button onclick="executeSelectedImport()" style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">Import Products</button>
            </div>
        </div>
    </div>
</div>
<script>
if (typeof openImportProductsModal === 'undefined') {
    function openImportProductsModal() {
        const modal = document.getElementById('importProductsModal');
        if (modal) modal.style.display = 'block';
    }
}
// No-op placeholders to avoid errors if not present on this page
if (typeof updateImportOptions === 'undefined') {
    function updateImportOptions() {}
}
if (typeof executeSelectedImport === 'undefined') {
    function executeSelectedImport() { alert('Product import action is wired on the Products page.'); }
}
</script>

