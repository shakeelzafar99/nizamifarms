{{--
    The customer's WhatsApp conversation, in a right-side drawer on whatever
    page includes this.

    ⭐ It embeds the REAL Messages chat (/messages?embed=1&focus_phone=…) in an
    iframe rather than reimplementing anything: full history, the customer's
    screenshots, replies and the template picker all work exactly as they do on
    the Messages page. `embed=1` selects the chrome-less layout; the width forces
    that page's own ≤768px mobile layout, where the chat fills the panel.
    focus_phone opens the right conversation by last-9-digit match, so PK number
    formatting variants don't matter.

    ⭐ Shared by Online Approvals and Daily Closing. It began as one page's inline
    copy; a second copy would have drifted the moment either changed.

    Usage:  @include('partials.wa-chat-drawer')
            openWaChatDrawer(phone, name)   /   closeWaChatDrawer()

    ⚠ Callers must hide their 💬 buttons for users without WhatsApp access —
    /messages is permission-gated and would answer 403 inside the frame.
--}}
<style>
    /* ── In-app WhatsApp chat drawer (opens the customer's chat in a right-side
          panel on THIS page, instead of navigating away to /messages) ── */
    .wa-chat-overlay {
        position: fixed; inset: 0; z-index: 4200;
        background: rgba(15, 23, 42, .45);
        display: none; justify-content: flex-end;
        opacity: 0; transition: opacity .2s ease;
    }
    .wa-chat-overlay.open { display: flex; opacity: 1; }
    .wa-chat-drawer {
        width: min(560px, 94vw); height: 100%;
        background: #fff; display: flex; flex-direction: column;
        box-shadow: -8px 0 28px rgba(0, 0, 0, .22);
        transform: translateX(100%); transition: transform .25s ease;
    }
    .wa-chat-overlay.open .wa-chat-drawer { transform: translateX(0); }
    .wa-chat-drawer-hdr {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; padding: 10px 14px; background: #075E54; color: #fff; flex-shrink: 0;
    }
    .wa-chat-drawer-title {
        font-weight: 700; font-size: 15px; overflow: hidden;
        text-overflow: ellipsis; white-space: nowrap;
    }
    .wa-chat-drawer-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .wa-chat-drawer-btn {
        background: rgba(255, 255, 255, .15); color: #fff; border: none; border-radius: 6px;
        width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
        cursor: pointer; font-size: 15px; text-decoration: none; line-height: 1;
    }
    .wa-chat-drawer-btn:hover { background: rgba(255, 255, 255, .3); }
    .wa-chat-frame { flex: 1; width: 100%; border: none; }
</style>

<div id="waChatOverlay" class="wa-chat-overlay" aria-hidden="true"
     onclick="if (event.target === this) closeWaChatDrawer()">
    <aside class="wa-chat-drawer" role="dialog" aria-label="WhatsApp chat">
        <div class="wa-chat-drawer-hdr">
            <span class="wa-chat-drawer-title" id="waChatDrawerTitle">💬 WhatsApp</span>
            <div class="wa-chat-drawer-actions">
                <a id="waChatDrawerFull" class="wa-chat-drawer-btn" href="#" target="_blank"
                   title="Open the full Messages page in a new tab">↗</a>
                <button type="button" class="wa-chat-drawer-btn" onclick="closeWaChatDrawer()"
                        title="Close">✕</button>
            </div>
        </div>
        <iframe id="waChatFrame" class="wa-chat-frame" title="WhatsApp chat" src="about:blank"></iframe>
    </aside>
</div>

<script>
// ── In-app WhatsApp chat drawer ──────────────────────────────────────────────
// Opens the customer's WhatsApp conversation in a right-side drawer on THIS
// page (embedding the Messages chat via an iframe) instead of navigating away.
function openWaChatDrawer(phone, name) {
    if (!phone) return;
    const overlay = document.getElementById('waChatOverlay');
    const frame   = document.getElementById('waChatFrame');
    const title   = document.getElementById('waChatDrawerTitle');
    const full    = document.getElementById('waChatDrawerFull');
    if (!overlay || !frame) return;
    // embed=1 → chrome-less Messages layout; focus_phone auto-opens the chat.
    frame.src = '/messages?embed=1&focus_phone=' + encodeURIComponent(phone);
    if (full)  full.href = '/messages?focus_phone=' + encodeURIComponent(phone);
    if (title) title.textContent = '💬 ' + (name || 'WhatsApp');
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function closeWaChatDrawer() {
    const overlay = document.getElementById('waChatOverlay');
    const frame   = document.getElementById('waChatFrame');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    // Unload the iframe so its polling/network stops while the drawer is closed.
    if (frame) frame.src = 'about:blank';
}
// True while the drawer is on screen. Pages with their own Esc handling ask
// this first, so Esc closes the drawer rather than acting on what is behind it.
function waChatDrawerIsOpen() {
    const o = document.getElementById('waChatOverlay');
    return !!(o && o.classList.contains('open'));
}
// Esc closes the drawer, and MARKS the event as consumed.
//
// ⚠ Why the mark: a host page may have its own Escape handler (Daily Closing
// restores a maximized pane). Both listeners sit on document, so both run for
// one keypress — and by the time the second checks "is the drawer open?" this
// one has already closed it, so a single Esc did two things. The flag rides on
// the event object itself, which every later listener receives.
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && waChatDrawerIsOpen()) {
        closeWaChatDrawer();
        e.nfHandledByChatDrawer = true;
    }
});
</script>
