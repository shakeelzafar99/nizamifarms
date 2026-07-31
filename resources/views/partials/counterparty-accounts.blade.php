{{--
  "Known bank accounts" — the review side of the NF Assistant's account memory
  (t_ai_counterparty_map). Include once per page, then call:

      nfCounterpartyAccounts.mount(el, 'vendor'|'customer', entityId)

  It renders into `el` and handles its own fetch + remove. Self-contained on
  purpose: it is embedded both in the Ledger Hub vendor page (server-rendered
  Blade) and in the customers page detail modal (a 5,000-line file where a
  drop-in is far safer than surgery).

  Removing a rule only stops the SUGGESTION — it never touches money or history.
--}}
<style>
  .nfca{--l:#e6e8ee;--i3:#98a2b3;--i2:#4b5563;font-size:13px}
  .nfca .nfca-h{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;
      letter-spacing:.06em;text-transform:uppercase;color:var(--i3);margin-bottom:6px}
  .nfca .nfca-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--l)}
  .nfca .nfca-row:first-of-type{border-top:0}
  .nfca .nfca-key{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700}
  .nfca .nfca-sub{font-size:11.5px;color:var(--i3);margin-top:1px}
  .nfca .nfca-x{margin-left:auto;border:1px solid var(--l);background:#fff;border-radius:8px;
      padding:4px 10px;font-size:12px;font-weight:700;color:#b42318;cursor:pointer}
  .nfca .nfca-x:hover{background:#fef3f2}
  .nfca .nfca-x:disabled{opacity:.5;cursor:default}
  .nfca .nfca-empty{color:var(--i3);padding:6px 0}
  .nfca .nfca-off{opacity:.5}
  .nfca .nfca-tag{font-size:10px;font-weight:800;padding:1px 6px;border-radius:999px;
      background:#FFFBEB;color:#92400E;border:1px solid #FCD34D}
  .nfca .nfca-add{margin-left:auto;border:1px solid var(--l);background:#fff;border-radius:8px;
      padding:3px 10px;font-size:11.5px;font-weight:700;color:#0F7A38;cursor:pointer;text-transform:none;letter-spacing:0}
  .nfca .nfca-add:hover{background:#E5F4EA}
  .nfca .nfca-form{background:#f6f8f5;border:1px solid var(--l);border-radius:10px;padding:10px;margin:6px 0}
  .nfca .nfca-form input{width:100%;border:1px solid var(--l);border-radius:8px;padding:7px 10px;
      font-size:13px;font-family:ui-monospace,Menlo,Consolas,monospace;outline:none;background:#fff}
  .nfca .nfca-form input:focus{border-color:#0F7A38}
  .nfca .nfca-hint{font-size:11.5px;color:var(--i3);margin:6px 0 8px}
  .nfca .nfca-form .nfca-save{border:0;background:#0F7A38;color:#fff;border-radius:8px;
      padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer}
  .nfca .nfca-form .nfca-cancel{border:1px solid var(--l);background:#fff;border-radius:8px;
      padding:6px 12px;font-size:12.5px;font-weight:700;color:var(--i2);cursor:pointer;margin-left:6px}
  .nfca .nfca-msg{font-size:12px;font-weight:600;margin-top:8px}
  .nfca .nfca-msg.ok{color:#0F7A38}
  .nfca .nfca-msg.err{color:#b42318}
</style>
<script>
window.nfCounterpartyAccounts = (function(){
  const LIST = @json(route('fin.counterparty-accounts.index'));
  const ADD  = @json(route('fin.counterparty-accounts.store'));
  const OFF  = id => @json(url('finance/counterparty-accounts')) + '/' + id + '/deactivate';
  const CSRF = @json(csrf_token());
  const READONLY = @json((bool) (auth()->user()?->isReadOnly() ?? false));
  const esc = s => (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  async function load(el, type, id){
    el.classList.add('nfca');
    el.innerHTML = '<div class="nfca-h">Known bank accounts</div><div class="nfca-empty">Loading…</div>';
    let rows = [];
    try {
      const r = await fetch(LIST + '?entity_type=' + encodeURIComponent(type) + '&entity_id=' + encodeURIComponent(id),
        {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
      const d = await r.json();
      if (!d.success) throw new Error();
      rows = d.accounts || [];
    } catch(e) {
      el.innerHTML = '<div class="nfca-h">Known bank accounts</div><div class="nfca-empty">Could not load.</div>';
      return;
    }
    render(el, type, id, rows);
  }

  function render(el, type, id, rows){
    const head = '<div class="nfca-h">Known bank accounts'
      + (rows.length ? '<span style="margin-left:8px;font-weight:600;text-transform:none;letter-spacing:0">'
          + rows.filter(r=>r.is_active).length + ' active</span>' : '')
      + (READONLY ? '' : '<button type="button" class="nfca-add" data-nfca-add>＋ Add account</button>')
      + '</div>';
    // Manual add — the input is normalized server-side through the SMS parser's
    // own patterns, and the response says how many past SMS match (typo check).
    const form = '<div class="nfca-form" data-nfca-form style="display:none">'
      + '<input type="text" data-nfca-input placeholder="e.g. PK96UNILxx322 or MBL ACxxx4602" />'
      + '<div class="nfca-hint">Copy the account <b>exactly as a bank SMS shows it</b> (masked). A full account number from a statement will never match, because SMS only carry these fragments.</div>'
      + '<button type="button" class="nfca-save" data-nfca-save>Save</button>'
      + '<button type="button" class="nfca-cancel" data-nfca-cancel>Cancel</button>'
      + '<div class="nfca-msg" data-nfca-msg></div>'
      + '</div>';
    if (!rows.length){
      el.innerHTML = head + form + '<div class="nfca-empty">None yet. Tag a bank SMS in the NF Assistant, or add one here with ＋ Add account.</div>';
      wireAdd(el, type, id);
      return;
    }
    el.innerHTML = head + form + rows.map(r => {
      const seen = r.hit_count > 0
        ? ('recognised ' + r.hit_count + '×' + (r.last_seen ? ', last ' + esc(r.last_seen) : ''))
        : 'no bank SMS matched yet';
      const by = r.added_by ? ' · added by ' + esc(r.added_by) : '';
      return '<div class="nfca-row' + (r.is_active ? '' : ' nfca-off') + '">'
        + '<div style="min-width:0">'
        +   '<div class="nfca-key">' + esc(r.account_key || r.name_key || '—')
        +     (r.is_name_only ? ' <span class="nfca-tag">name only</span>' : '')
        +     (r.is_active ? '' : ' <span class="nfca-tag">removed</span>')
        +   '</div>'
        +   '<div class="nfca-sub">' + seen + (r.added ? ' · added ' + esc(r.added) : '') + by + '</div>'
        + '</div>'
        + (r.is_active && !READONLY
            ? '<button class="nfca-x" data-off="' + r.id + '">Remove</button>' : '')
        + '</div>';
    }).join('');

    el.querySelectorAll('[data-off]').forEach(b => {
      b.addEventListener('click', async () => {
        const key = b.closest('.nfca-row').querySelector('.nfca-key').textContent.trim();
        if (!window.confirm('Stop recognising ' + key + ' automatically?\n\nFuture bank SMS from this account will need tagging again. Nothing already recorded changes.')) return;
        b.disabled = true;
        try {
          const r = await fetch(OFF(b.dataset.off), {method:'POST', credentials:'same-origin',
            headers:{'Accept':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}});
          const d = await r.json();
          if (!d.success) { window.alert(d.message || 'Could not remove that.'); b.disabled = false; return; }
        } catch(e) { window.alert('Could not remove that.'); b.disabled = false; return; }
        load(el, type, id);
      });
    });
    wireAdd(el, type, id);
  }

  function wireAdd(el, type, id){
    const btn = el.querySelector('[data-nfca-add]');
    const form = el.querySelector('[data-nfca-form]');
    if (!btn || !form) return;
    const input = form.querySelector('[data-nfca-input]');
    const msg = form.querySelector('[data-nfca-msg]');
    btn.addEventListener('click', () => {
      form.style.display = form.style.display === 'none' ? '' : 'none';
      if (form.style.display === '') input.focus();
    });
    form.querySelector('[data-nfca-cancel]').addEventListener('click', () => { form.style.display = 'none'; });
    const save = async () => {
      const account = input.value.trim();
      if (!account) { input.focus(); return; }
      const saveBtn = form.querySelector('[data-nfca-save]');
      saveBtn.disabled = true;
      msg.className = 'nfca-msg'; msg.textContent = 'Saving…';
      try {
        const r = await fetch(ADD, {method:'POST', credentials:'same-origin',
          headers:{'Accept':'application/json','Content-Type':'application/json',
                   'X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({entity_type: type, entity_id: id, account})});
        const d = await r.json();
        msg.className = 'nfca-msg ' + (d.success ? 'ok' : 'err');
        msg.textContent = d.message || (d.success ? 'Saved.' : 'Could not save that.');
        if (d.success && !d.already) {
          // Re-render after a beat so the save-message (with its past-SMS
          // count) is actually readable before the list replaces it.
          setTimeout(() => load(el, type, id), 2600);
        }
      } catch(e) {
        msg.className = 'nfca-msg err'; msg.textContent = 'Could not save that.';
      }
      saveBtn.disabled = false;
    };
    form.querySelector('[data-nfca-save]').addEventListener('click', save);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') save(); });
  }

  return {mount: load};
})();
</script>
