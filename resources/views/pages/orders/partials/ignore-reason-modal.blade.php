{{--
  ⭐ THE "WHY ARE WE IGNORING THIS?" PICKER — one modal, both web call sites.

  The approvals DRAWER (an IIFE, ~line 2330 of index.blade.php) and the full Shopify
  TABLE (`ignoreOrder`, ~line 5180) are in different script scopes and are gated
  differently — the drawer only renders under $canViewShopify, the table does not. So
  this lives in its own partial, included UNCONDITIONALLY, and publishes exactly one
  global: `window.nfPickIgnoreReason({orderNumber, onPick})`.

  ⚠ WRAPPED IN @verbatim ON PURPOSE. There is not a single Blade variable in here, and
    the JS below contains braces that Blade would otherwise be free to misread — the
    same class of trap that has bitten this codebase before. Nothing to interpolate,
    so nothing is interpolated.
--}}
@verbatim
<style>
#nfirScrim{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:120;display:none}
#nfirScrim.nfir-on{display:block}
#nfirBox{position:fixed;z-index:121;left:50%;top:50%;transform:translate(-50%,-50%);
  width:min(420px,calc(100vw - 32px));background:#fff;border-radius:14px;
  box-shadow:0 24px 60px rgba(15,23,42,.3);font-family:inherit;display:none;overflow:hidden}
#nfirBox.nfir-on{display:block}
.nfir-head{padding:16px 18px 12px;border-bottom:1px solid #eef2f7}
.nfir-title{font-size:15px;font-weight:800;color:#0f172a;margin:0}
.nfir-sub{font-size:12px;color:#64748b;margin-top:3px}
.nfir-body{padding:10px}
.nfir-opt{display:block;width:100%;text-align:left;background:#fff;border:1px solid #e2e8f0;
  border-radius:10px;padding:11px 13px;margin:6px 0;cursor:pointer;font-family:inherit;transition:.15s}
.nfir-opt:hover{border-color:#94a3b8;background:#f8fafc}
.nfir-opt-t{font-size:13.5px;font-weight:700;color:#0f172a}
.nfir-opt-h{font-size:11.5px;color:#64748b;margin-top:2px;line-height:1.45}
.nfir-opt.nfir-wa{border-color:#bbf7d0;background:#f0fdf4}
.nfir-opt.nfir-wa:hover{border-color:#4ade80;background:#dcfce7}
.nfir-opt.nfir-quiet .nfir-opt-t{color:#334155}
.nfir-foot{padding:8px 14px 14px;text-align:right}
.nfir-cancel{background:none;border:0;color:#64748b;font-size:13px;font-weight:600;
  cursor:pointer;font-family:inherit;padding:7px 10px}
.nfir-cancel:hover{color:#0f172a}
</style>

<div id="nfirScrim" onclick="nfirClose()"></div>
<div id="nfirBox" role="dialog" aria-modal="true" aria-labelledby="nfirTitle">
  <div class="nfir-head">
    <p class="nfir-title" id="nfirTitle">Ignore this order?</p>
    <div class="nfir-sub" id="nfirSub">No invoice will be created.</div>
  </div>
  <div class="nfir-body">
    <button type="button" class="nfir-opt nfir-quiet" onclick="nfirPick('none')">
      <div class="nfir-opt-t">Ignore without a message</div>
      <div class="nfir-opt-h">The customer is not told anything.</div>
    </button>
    <button type="button" class="nfir-opt nfir-wa" onclick="nfirPick('out_of_area')">
      <div class="nfir-opt-t">💬 Outside our delivery area</div>
      <div class="nfir-opt-h">WhatsApps the customer that we do not deliver to their address yet.</div>
    </button>
    <button type="button" class="nfir-opt nfir-wa" onclick="nfirPick('customer_request')">
      <div class="nfir-opt-t">💬 Customer asked to cancel</div>
      <div class="nfir-opt-h">WhatsApps the customer confirming we cancelled it on their request.</div>
    </button>
  </div>
  <div class="nfir-foot"><button type="button" class="nfir-cancel" onclick="nfirClose()">Cancel</button></div>
</div>

<script>
(function(){
  var pending = null;   // the onPick callback of the open modal, or null

  function el(id){ return document.getElementById(id); }

  /**
   * Ask which kind of ignore this is.
   * @param {{orderNumber?:string, onPick:function(string)}} opts
   *        onPick receives 'none' | 'out_of_area' | 'customer_request'.
   *        Cancelling calls nothing at all — the caller must leave its button alone
   *        until onPick fires, or a cancelled dialog would leave a dead "…" button.
   */
  window.nfPickIgnoreReason = function(opts){
    opts = opts || {};
    pending = typeof opts.onPick === 'function' ? opts.onPick : null;
    var num = opts.orderNumber ? String(opts.orderNumber) : '';
    el('nfirSub').textContent = num
      ? ('Order ' + num + ' · no invoice will be created.')
      : 'No invoice will be created.';
    el('nfirScrim').classList.add('nfir-on');
    el('nfirBox').classList.add('nfir-on');
  };

  window.nfirClose = function(){
    pending = null;
    el('nfirScrim').classList.remove('nfir-on');
    el('nfirBox').classList.remove('nfir-on');
  };

  window.nfirPick = function(reason){
    var cb = pending;
    // Close FIRST so the callback is free to open an alert/confirm of its own.
    window.nfirClose();
    if(cb){ cb(reason); }
  };

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && el('nfirBox') && el('nfirBox').classList.contains('nfir-on')){
      window.nfirClose();
    }
  });
})();
</script>
@endverbatim
