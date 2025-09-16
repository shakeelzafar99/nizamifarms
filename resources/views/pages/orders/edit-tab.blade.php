@extends('layouts.app')

@section('title', 'Edit Order')

@section('content')
<div class="container-fixed">
    <div class="card card-grid">
        <div class="card-header">
            <h3 class="card-title">Edit Invoice #{{ $order->order_number ?? $order->id }}</h3>
            <a href="{{ url()->previous() }}" class="kt-btn kt-btn-light">Back</a>
        </div>
        <div class="card-body">
            {{-- Reuse the same markup as modal: render edit form body using existing function output --}}
            <div id="editOrderContent">
                {{-- We reuse the JS builder that normally fills the modal. Here we seed it with server data to render once. --}}
            </div>
        </div>
    </div>
</div>

<script>
// Minimal bootstrap: call the same client-side renderer you already use for the modal
(function() {
  try {
    const order = @json($order);
    // Use the existing showEditOrder modal renderer's internal builder by emulating the response
    // We call the same function that populates #editOrderContent in the modal
    if (typeof buildEditOrderHtml === 'function') {
      document.getElementById('editOrderContent').innerHTML = buildEditOrderHtml(order);
      // attach the submission handler like modal does
      const form = document.getElementById('editOrderForm');
      if (form) {
        form.addEventListener('submit', function(e){ e.preventDefault(); saveOrderChanges(order.id); });
      }
    } else {
      // Fallback: load the modal-based renderer by simulating the click flow via a fetch
      fetch('/orders/' + order.id)
        .then(r=>r.json())
        .then(d=>{
          if (d.success) {
            // Reuse the same code path used in viewOrderDetails -> edit builder
            const modal = {style:{}}; // stub
            // If you have a dedicated builder function, call it here; otherwise, insert a message
            document.getElementById('editOrderContent').innerHTML = '<div style="padding:12px; color:#6b7280;">Please open from main page if content does not render.</div>';
          }
        });
    }
  } catch (e) { console.error(e); }
})();
</script>
@endsection

