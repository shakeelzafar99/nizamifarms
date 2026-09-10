{{--
    RETURN A DELIVERED ORDER (Sep-2026).

    Three questions, in the order the manager can actually answer them:
      1. Why did it come back?
      2. What happens to the money?  ← the server pre-selects the answer its
         ledger state implies and offers every OTHER legal answer beside it, so
         the common case is one click and the unusual case is still possible.
      3. What happens to the goods?  ← freezer (default) / chiller / written off.

    ⚠ Nothing here is trusted. Every option shown was computed by
    OrderReturnService::detectMoney(), and it is recomputed on submit — a form
    left open while the rider settles his cash cannot post a reversal that has
    since become illegal. This markup only asks; the server decides.

    ⚠⚠ OWN SCOPED CSS, NO TAILWIND. The loaded stylesheet is Metronic v9 (kt-
    classes only) — `fixed`, `inset-0`, `z-[…]`, `hidden` exist in NO loaded CSS.
    The first cut of this modal used them and "did nothing" when clicked: it
    rendered, unstyled, at the very bottom of the page. Same lesson the sibling
    ignore-reason modal already carries.

    Included unconditionally; it opens only via nfOpenReturnForm(), which is
    only reachable when the server said this user may return orders.
--}}
<style>
#nfrScrim{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:10000;display:none}
#nfrScrim.nfr-on{display:block}
#nfrBox{position:fixed;z-index:10001;left:50%;top:50%;transform:translate(-50%,-50%);
  width:min(680px,calc(100vw - 32px));max-height:calc(100vh - 32px);background:#fff;border-radius:14px;
  box-shadow:0 24px 60px rgba(15,23,42,.3);font-family:inherit;display:none;overflow:hidden;
  flex-direction:column;color:#0f172a}
#nfrBox.nfr-on{display:flex}
.nfr-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef2f7}
.nfr-title{font-size:15px;font-weight:800;margin:0}
.nfr-x{background:none;border:0;font-size:24px;line-height:1;color:#94a3b8;cursor:pointer;padding:0 4px}
.nfr-x:hover{color:#0f172a}
.nfr-body{padding:16px 18px;overflow-y:auto;flex:1}
.nfr-loading,.nfr-empty{padding:34px 18px;text-align:center;color:#64748b;font-size:13px}
.nfr-blocked{margin:16px 18px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;border-radius:10px;padding:12px 14px;font-size:13px}
.nfr-order{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-size:13px;margin-bottom:16px}
.nfr-order b{font-weight:700}
.nfr-order small{display:block;color:#64748b;font-size:11.5px;margin-top:2px}
.nfr-q{font-size:13px;font-weight:700;color:#334155;margin:0 0 6px}
.nfr-sec{margin-bottom:18px}
.nfr-input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;color:#0f172a}
.nfr-input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15)}
.nfr-opt{display:flex;gap:10px;align-items:flex-start;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin:6px 0;cursor:pointer;background:#fff;transition:.15s}
.nfr-opt:hover{border-color:#94a3b8;background:#f8fafc}
.nfr-opt input{margin-top:3px;flex:none}
.nfr-opt-t{font-size:13.5px;font-weight:700}
.nfr-opt-h{font-size:11.5px;color:#64748b;margin-top:2px;line-height:1.45}
.nfr-tag{display:inline-block;margin-left:6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#166534;background:#dcfce7;border-radius:5px;padding:2px 6px;vertical-align:middle}
.nfr-tip{margin-top:10px;border:1px solid #c7d2fe;background:#eef2ff;color:#312e81;border-radius:10px;padding:9px 12px;font-size:13px;display:flex;gap:8px;align-items:flex-start;cursor:pointer}
.nfr-note{font-size:11.5px;color:#64748b;margin-top:8px}
.nfr-sub{margin-top:10px;padding-left:12px;border-left:2px solid #e2e8f0}
.nfr-chips{display:flex;gap:8px;margin-top:4px}
.nfr-chip{display:flex;align-items:center;gap:6px;border:1px solid #e2e8f0;border-radius:8px;padding:7px 12px;font-size:13px;cursor:pointer;background:#fff}
.nfr-chip:hover{background:#f8fafc}
.nfr-err{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:10px;padding:9px 12px;font-size:13px;margin-top:8px}
.nfr-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;border-top:1px solid #eef2f7;background:#f8fafc}
.nfr-foot small{font-size:11.5px;color:#64748b;flex:1}
.nfr-btn{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.nfr-btn:hover{background:#f1f5f9}
.nfr-btn.nfr-go{background:#6d28d9;border-color:#6d28d9;color:#fff}
.nfr-btn.nfr-go:hover{background:#5b21b6}
.nfr-btn:disabled{opacity:.5;cursor:default}
.nfr-hide{display:none !important}
</style>

<div id="nfrScrim" onclick="nfCloseReturnForm()"></div>
<div id="nfrBox" role="dialog" aria-modal="true" aria-labelledby="nfrTitle">
    <div class="nfr-head">
        <h3 class="nfr-title" id="nfrTitle">↩ Return order</h3>
        <button type="button" class="nfr-x" onclick="nfCloseReturnForm()" aria-label="Close">&times;</button>
    </div>

    {{-- loading / blocked / form: exactly one is visible at a time --}}
    <div id="nfReturnLoading" class="nfr-loading">Reading this order…</div>

    <div id="nfReturnBlocked" class="nfr-hide">
        <div class="nfr-blocked" id="nfReturnBlockedText"></div>
        <div class="nfr-foot"><small></small><button type="button" class="nfr-btn" onclick="nfCloseReturnForm()">Close</button></div>
    </div>

    <div id="nfReturnForm" class="nfr-hide" style="display:flex;flex-direction:column;min-height:0">
        <div class="nfr-body">
            <div class="nfr-order">
                <b id="nfReturnOrderLine"></b>
                <small id="nfReturnStateLine"></small>
            </div>

            {{-- 1. why --}}
            <div class="nfr-sec">
                <p class="nfr-q">Why is it coming back?</p>
                <input type="text" id="nfReturnReason" maxlength="255" class="nfr-input"
                       placeholder="e.g. wrong item, quality complaint, customer refused">
            </div>

            {{-- 2. money --}}
            <div class="nfr-sec">
                <p class="nfr-q">What happens to the money?</p>
                <div id="nfReturnMoneyOptions"></div>
                <label id="nfReturnTipRow" class="nfr-tip nfr-hide">
                    <input type="checkbox" id="nfReturnTip">
                    <span id="nfReturnTipLabel"></span>
                </label>
                <div id="nfReturnTipNote" class="nfr-note nfr-hide"></div>
                <div id="nfReturnCreditNote" class="nfr-note nfr-hide"></div>
            </div>

            {{-- 3. goods --}}
            <div class="nfr-sec">
                <p class="nfr-q">What happens to the goods?</p>
                <label class="nfr-opt">
                    <input type="radio" name="nfReturnGoods" value="restock" checked onchange="nfReturnGoodsChanged()">
                    <span><span class="nfr-opt-t">Put it back into stock</span><span class="nfr-opt-h" id="nfReturnGoodsSummary" style="display:block"></span></span>
                </label>
                <label class="nfr-opt">
                    <input type="radio" name="nfReturnGoods" value="wasted" onchange="nfReturnGoodsChanged()">
                    <span><span class="nfr-opt-t">Written off — spoiled or damaged</span><span class="nfr-opt-h" style="display:block">Nothing is added back to stock and the store is not asked to scan anything.</span></span>
                </label>

                <div id="nfReturnSectionRow" class="nfr-sub nfr-hide">
                    <p class="nfr-q">Where does the fresh meat go?</p>
                    <div class="nfr-chips">
                        <label class="nfr-chip"><input type="radio" name="nfReturnSection" value="freezer" checked> Freezer</label>
                        <label class="nfr-chip"><input type="radio" name="nfReturnSection" value="chiller"> Chiller</label>
                    </div>
                    <div class="nfr-note">The store can still change this per packet while scanning.</div>
                </div>
            </div>

            <div id="nfReturnError" class="nfr-err nfr-hide"></div>
        </div>

        <div class="nfr-foot">
            <small>The money is recorded now. The goods move when the store scans them back.</small>
            <button type="button" class="nfr-btn" onclick="nfCloseReturnForm()">Cancel</button>
            <button type="button" id="nfReturnSubmit" class="nfr-btn nfr-go" onclick="nfSubmitReturn()">Return this order</button>
        </div>
    </div>
</div>

<script>
window.nfCanReturnOrders = @json($canReturnOrders ?? false);
(function () {
    let state = null;   // the server's preview for the order being returned
    let busy  = false;

    const $ = (id) => document.getElementById(id);
    const show = (id, on) => $(id).classList.toggle('nfr-hide', !on);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = (n) => 'Rs ' + Number(n || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Every id-based order URL on this page must carry the source (Shopify
    // staging ids collide with live ids). A return only ever applies to a LIVE
    // delivered order, so the pill never renders on the staging tab — but the
    // guard here makes that a rule, not a hope.
    function liveOnly() {
        const src = (typeof currentOrderSource === 'function') ? currentOrderSource() : (window.currentSource || '');
        return String(src || '').toLowerCase() !== 'shopify';
    }

    window.nfOpenReturnForm = async function (orderId) {
        if (!liveOnly()) {
            alert('Returns apply to live delivered orders. Open the order from the Invoices / Open Orders tab.');
            return;
        }
        $('nfrScrim').classList.add('nfr-on');
        $('nfrBox').classList.add('nfr-on');
        show('nfReturnLoading', true);
        show('nfReturnForm', false);
        show('nfReturnBlocked', false);
        show('nfReturnError', false);
        state = null;

        try {
            const r = await fetch(`/orders/${orderId}/return/preview`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const j = await r.json();
            show('nfReturnLoading', false);

            if (!j.success || !j.preview || j.preview.eligible === false) {
                $('nfReturnBlockedText').textContent = (j.preview && j.preview.blocked) || j.message || 'This order cannot be returned.';
                show('nfReturnBlocked', true);
                return;
            }
            state = j.preview;
            render();
            show('nfReturnForm', true);
        } catch (e) {
            show('nfReturnLoading', false);
            $('nfReturnBlockedText').textContent = 'Could not read this order: ' + e.message;
            show('nfReturnBlocked', true);
        }
    };

    window.nfCloseReturnForm = function () {
        $('nfrScrim').classList.remove('nfr-on');
        $('nfrBox').classList.remove('nfr-on');
        state = null;
    };

    function render() {
        $('nfReturnOrderLine').textContent = `${state.order_number} — ${state.customer_name} — ${money(state.order_total)}`;
        $('nfReturnStateLine').textContent = state.money.state_label;
        $('nfReturnReason').value = '';

        // --- money options. The recommended one is pre-selected; the others stay
        //     visible so an unusual case is still one click, not a dead end.
        const box = $('nfReturnMoneyOptions');
        box.innerHTML = '';
        (state.money.options || []).forEach((opt) => {
            const checked = opt.key === state.money.default ? 'checked' : '';
            const rec = (opt.recommended || opt.key === state.money.default) ? '<span class="nfr-tag">suggested</span>' : '';
            box.insertAdjacentHTML('beforeend', `
                <label class="nfr-opt">
                    <input type="radio" name="nfReturnMoney" value="${esc(opt.key)}" ${checked} onchange="nfReturnMoneyChanged()">
                    <span>
                        <span class="nfr-opt-t" data-key="${esc(opt.key)}" data-label="${esc(opt.label)}">${esc(opt.label)}</span>${rec}
                        ${opt.help ? `<span class="nfr-opt-h" style="display:block">${esc(opt.help)}</span>` : ''}
                    </span>
                </label>`);
        });

        // --- tip
        if (state.tip.ask) {
            $('nfReturnTipLabel').textContent = `Give the ${money(state.tip.collected)} tip back to the customer as well`;
            $('nfReturnTip').checked = false;
        }
        if (state.tip.note) { $('nfReturnTipNote').textContent = state.tip.note; }
        show('nfReturnTipNote', !!state.tip.note);

        // --- account balance the customer had spent on this order
        if (state.money.credit_applied > 0) {
            $('nfReturnCreditNote').textContent =
                `${money(state.money.credit_applied)} of the customer's account balance was used on this order. It goes back to their balance automatically.`;
        }
        show('nfReturnCreditNote', state.money.credit_applied > 0);

        // --- goods
        const g = state.goods || { frozen_count: 0, fresh_count: 0, has_meat: false };
        const bits = [];
        if (g.fresh_count) bits.push(`${g.fresh_count} fresh item${g.fresh_count > 1 ? 's' : ''} to a shelf`);
        if (g.frozen_count) bits.push(`${g.frozen_count} frozen item${g.frozen_count > 1 ? 's' : ''} back to Frozen stock`);
        $('nfReturnGoodsSummary').textContent = bits.length
            ? bits.join(', ') + ' — the store is asked to scan them back in.'
            : 'Nothing on this order needs scanning.';
        document.querySelector('input[name="nfReturnGoods"][value="restock"]').checked = true;
        document.querySelector('input[name="nfReturnSection"][value="freezer"]').checked = true;
        nfReturnGoodsChanged();
        nfReturnMoneyChanged();
    }

    /** What the customer would actually be handed under this answer. */
    function payableAmount() {
        if (!state) return 0;
        const tipBack = state.tip.ask && $('nfReturnTip').checked;
        return tipBack ? state.money.amount : Math.max(0, state.money.amount - (state.tip.collected || 0));
    }

    window.nfReturnGoodsChanged = function () {
        const restock = document.querySelector('input[name="nfReturnGoods"]:checked')?.value === 'restock';
        show('nfReturnSectionRow', restock && !!(state && state.goods && state.goods.has_meat));
    };

    window.nfReturnMoneyChanged = function () {
        // Reversing an invoice hands the tip back on its own, so the question is
        // not asked in that case — it is stated instead.
        const chosen = document.querySelector('input[name="nfReturnMoney"]:checked')?.value;
        show('nfReturnTipRow', !!(state && state.tip.ask && chosen !== 'reversed'));
        renderAmounts();
    };

    /** Keep the "— Rs X" on refund/credit in step with the tip checkbox. */
    function renderAmounts() {
        if (!state) return;
        document.querySelectorAll('#nfReturnMoneyOptions .nfr-opt-t').forEach(el => {
            const key = el.dataset.key;
            el.textContent = (key === 'refund' || key === 'credit')
                ? el.dataset.label + ' — ' + money(payableAmount())
                : el.dataset.label;
        });
    }
    document.addEventListener('change', (e) => { if (e.target && e.target.id === 'nfReturnTip') renderAmounts(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('nfrBox').classList.contains('nfr-on')) nfCloseReturnForm(); });

    window.nfSubmitReturn = async function () {
        if (!state || busy) return;
        const chosen = document.querySelector('input[name="nfReturnMoney"]:checked')?.value;
        if (!chosen) { showError('Choose what happens to the money.'); return; }

        const goods = document.querySelector('input[name="nfReturnGoods"]:checked')?.value || 'restock';
        const section = document.querySelector('input[name="nfReturnSection"]:checked')?.value || 'freezer';

        busy = true;
        $('nfReturnSubmit').disabled = true;
        $('nfReturnSubmit').textContent = 'Working…';
        show('nfReturnError', false);

        try {
            const r = await fetch(`/orders/${state.order_id}/return`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    money_action: chosen,
                    goods_action: goods,
                    meat_section: goods === 'restock' ? section : null,
                    return_tip: !!(state.tip.ask && $('nfReturnTip').checked && chosen !== 'reversed'),
                    reason: $('nfReturnReason').value || null
                })
            });
            const j = await r.json();
            if (!j.success) { showError(j.message || 'The return could not be recorded.'); return; }

            nfCloseReturnForm();
            if (typeof showNotification === 'function') showNotification(j.message, 'success');
            else alert(j.message);
            if (typeof loadOrders === 'function') loadOrders();
            else window.location.reload();
        } catch (e) {
            showError('Something went wrong: ' + e.message);
        } finally {
            busy = false;
            $('nfReturnSubmit').disabled = false;
            $('nfReturnSubmit').textContent = 'Return this order';
        }
    };

    function showError(msg) {
        $('nfReturnError').textContent = msg;
        show('nfReturnError', true);
    }
})();
</script>
