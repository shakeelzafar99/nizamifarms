<!-- Shared Import Orders Modal (reused on Orders and Operations pages) -->
<div id="importOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Import Historical Orders</h3>
            <button onclick="closeModal('importOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 20px;">
            <form id="importOrderForm" action="{{ route('orders.importOrders') }}" method="POST">
                @csrf
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Select Source</label>
                    <select name="source" required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                        <option value="">Choose a source...</option>
                        <option value="Shopify">Shopify</option>
                        <option value="WooCommerce">WooCommerce</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">From Date</label>
                        <input type="date" name="from_date" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">To Date</label>
                        <input type="date" name="to_date" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" required>
                    </div>
                </div>

                <div style="background-color: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: start;">
                        <div style="color: #3b82f6; margin-right: 8px;">
                            <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div>
                            <h4 style="font-size: 14px; font-weight: 500; color: #1e40af; margin: 0 0 4px 0;">Import Information</h4>
                            <p style="font-size: 12px; color: #1e40af; margin: 0;">This will fetch and import orders from the selected platform within the specified date range. Existing orders will be updated if found.</p>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" onclick="closeModal('importOrderModal')" 
                            style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background-color: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                        Import Orders
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Define helpers only if they don't already exist (prevents redefinition on Orders page)
if (typeof openImportModal === 'undefined') {
    function openImportModal() {
        const modal = document.getElementById('importOrderModal');
        if (modal) modal.style.display = 'block';
        // Default last 30 days
        try {
            const to = document.querySelector('#importOrderModal input[name="to_date"]');
            const from = document.querySelector('#importOrderModal input[name="from_date"]');
            const today = new Date();
            const prior = new Date();
            prior.setDate(today.getDate() - 30);
            const fmt = d => d.toISOString().slice(0,10);
            if (to && !to.value) to.value = fmt(today);
            if (from && !from.value) from.value = fmt(prior);
        } catch (e) {}
    }
}
if (typeof closeModal === 'undefined') {
    function closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) modal.style.display = 'none';
    }
}
</script>

