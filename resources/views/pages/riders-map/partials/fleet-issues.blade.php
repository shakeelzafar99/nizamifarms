{{--
    🛠 THE ISSUES BOARD — the fleet's open problems, grouped BY MACHINE (Sep-15 2026).
    Plan: VEHICLE-ISSUES-BOARD-PLAN-SEP2026.md. Owner ask: a third Bikes tab that consolidates
    the per-vehicle chats, says whether issues are still open and whether a workshop day is
    assigned, "so I can see if there's any delay or if no one is closing these tickets".

    ⭐⭐ ONE PARTIAL, TWO DOORS. It is included by the Bikes tab (fleet.blade.php) and by the
       standalone planners' page (pages/fleet/issues-board). Copying the markup would mean two
       places to fix every time a chip or a colour changes — the same reasoning that gave
       flTicketRowsHtml two callers instead of two renderers.

    ⚠ The HOST decides whether a ticket row opens a thread INLINE:
        window.FL_ISS_INLINE_THREADS = true   → the Bikes tab, where flOpenTicket() lives
        (unset)                               → the standalone page, which links into Bikes
      There is deliberately no second thread renderer anywhere.

    ⚠ Own `flIss*` prefix. Assigning `flv*` state from outside its own eval creates a DIFFERENT
      binding and reports a working build as broken — the harness trap this screen hit twice.
--}}

<div id="flIssWrap" style="display:none;">
    <div class="fl-iss-bar">
        <div id="flIssStrip" class="fl-iss-strip"></div>
        <div id="flIssMeta" class="fl-iss-meta"></div>
    </div>
    <div id="flIssCards" class="fl-iss-cards"></div>
    <div id="flIssQuiet"></div>
</div>

<style>
.fl-iss-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid #e5e7eb;background:#fafafa;}
.fl-iss-strip{display:flex;gap:6px;flex-wrap:wrap;}
.fl-iss-meta{margin-left:auto;font-size:11.5px;color:#9ca3af;}
.fl-iss-chip{border:1px solid #d1d5db;background:#fff;border-radius:999px;padding:3px 11px;font-size:12px;
             font-weight:600;color:#374151;cursor:pointer;white-space:nowrap;font-variant-numeric:tabular-nums;}
.fl-iss-chip:hover{background:#f9fafb;}
.fl-iss-chip.on{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.fl-iss-chip b{font-weight:800;}
.fl-iss-chip.red b{color:#b91c1c;} .fl-iss-chip.on.red b,.fl-iss-chip.on b{color:#fff;}
.fl-iss-chip.amber b{color:#b45309;} .fl-iss-chip.purple b{color:#5b21b6;}
.fl-iss-cards{padding:14px 16px;display:grid;grid-template-columns:1fr;gap:12px;}
@media (min-width:1100px){.fl-iss-cards{grid-template-columns:1fr 1fr;align-items:start;}}
.fl-iss-card{border:1px solid #e5e7eb;border-radius:9px;background:#fff;overflow:hidden;
             box-shadow:0 1px 2px rgba(0,0,0,.04);border-left-width:4px;border-left-color:#9ca3af;}
/* The stripe IS the level — the colour and the sort come from one server-side rule. */
.fl-iss-card.red{border-left-color:#dc2626;} .fl-iss-card.amber{border-left-color:#d97706;}
.fl-iss-card.blue{border-left-color:#2563eb;} .fl-iss-card.green{border-left-color:#16a34a;}
.fl-iss-hd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:9px 12px 4px;}
.fl-iss-plate{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13.5px;font-weight:600;
              border:1.5px solid #111827;border-radius:4px;padding:0 6px;cursor:pointer;}
.fl-iss-who{font-size:12.5px;color:#4b5563;}
.fl-iss-tags{margin-left:auto;display:flex;gap:5px;flex-wrap:wrap;}
.fl-iss-tag{font-size:10.5px;font-weight:700;border-radius:4px;padding:2px 7px;white-space:nowrap;}
.fl-iss-attn{padding:0 12px 8px;font-size:12.5px;font-weight:700;}
.fl-iss-card.red .fl-iss-attn{color:#b91c1c;} .fl-iss-card.amber .fl-iss-attn{color:#b45309;}
.fl-iss-card.blue .fl-iss-attn{color:#1d4ed8;} .fl-iss-card.green .fl-iss-attn{color:#15803d;}
.fl-iss-card.grey .fl-iss-attn{color:#6b7280;}
.fl-iss-row{padding:8px 12px;border-top:1px solid #f1f5f9;}
.fl-iss-row.clickable{cursor:pointer;} .fl-iss-row.clickable:hover{background:#fafafa;}
.fl-iss-rowtop{display:flex;align-items:center;gap:8px;}
.fl-iss-title{flex:1;font-size:13px;font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.fl-iss-age{font-size:11.5px;color:#9ca3af;font-variant-numeric:tabular-nums;white-space:nowrap;}
.fl-iss-sub{display:flex;justify-content:space-between;gap:10px;font-size:11.5px;color:#6b7280;margin-top:3px;}
.fl-iss-turn{font-weight:700;white-space:nowrap;}
.fl-iss-ws{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid #f1f5f9;font-size:12.5px;}
.fl-iss-foot{display:flex;align-items:center;gap:8px;padding:7px 12px;border-top:1px dashed #e5e7eb;
             font-size:11.5px;color:#9ca3af;}
/* Closed rows are reachable but unmistakably different — struck, grey, no stripe, no turn. */
.fl-iss-closed{padding:6px 12px;border-top:1px solid #f1f5f9;background:#fafafa;color:#9ca3af;font-size:12px;}
.fl-iss-closed .t{text-decoration:line-through;}
.fl-iss-quiet{margin:0 16px 18px;border:1px dashed #e5e7eb;border-radius:9px;padding:10px 14px;
              font-size:12.5px;color:#6b7280;}
.fl-iss-empty{padding:40px 16px;text-align:center;color:#9ca3af;font-size:13px;}
</style>

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   THE ISSUES BOARD. Reads ONE endpoint (/fleet/issues) which has already done
   every judgement — whose turn it is, how stuck a machine is, what sentence to
   print. Nothing below decides anything; it draws what it is told, which is why
   the phone and the desk cannot disagree.
   ═══════════════════════════════════════════════════════════════════════════ */
var flIssData = null;
var flIssFilter = 'all';
var flIssMode = 'live';
var flIssSeq = 0;
var flIssTimer = null;
var flIssOpenThreadId = null;     // the thread expanded right now, if any
var flIssClosedOpen = {};         // vehicleId → showing its closed rows
/**
 * ⭐⭐ THE CARDS OPEN COLLAPSED (owner, 15-Sep). A card used to print every ticket row, the
 *    workshop line, the Schedule button and the closed footer at once, so on a bad week the
 *    sixth machine in trouble was several screens down — on a view whose whole job is
 *    "glance and know". Collapsed it is the header, the tags and the ONE sentence the server
 *    composed; a click opens the rest.
 *
 * ⚠ Holds ONLY the cards the user has clicked himself. Everything else is re-derived by
 *   `flIssAutoOpen` on each render, so changing the filter is not frozen by whatever the last
 *   filter happened to decide.
 */
var flIssExpanded = {};           // vehicleId → the user's OWN choice (true/false)

function flIssEsc(s) {
    var d = document.createElement('div');
    d.textContent = String(s === null || s === undefined ? '' : s);
    return d.innerHTML;
}
/* ⚠⚠ Quoting a JS string argument inside onclick="" — JSON.stringify emits a real `"` which
   ENDS the attribute and the handler silently never runs. Backslash for JS, THEN entities for
   HTML. (fleet.blade.php has flJsArg for the same reason; this partial must stand alone on the
   planners' page, where that file is not loaded.) */
function flIssArg(s) {
    var js = String(s === null || s === undefined ? '' : s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    return flIssEsc('"' + js + '"');
}

function flIssLoad(quiet) {
    var seq = ++flIssSeq;
    var url = '/orders/riders-map/fleet/issues?mode=' + encodeURIComponent(flIssMode);
    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
            if (seq !== flIssSeq || !j || !j.success) return;
            flIssData = j;
            /* ⚠ A thread that was open when flIssRefresh() ran: its card must be OPEN in the
                 redraw (cards collapse by default), and then the thread goes back into its box. */
            var reopen = flIssReopenTicket; flIssReopenTicket = null;
            var reopenVid = null;
            if (reopen) {
                (j.vehicles || []).forEach(function (c) {
                    if ((c.open_tickets || []).some(function (t) { return t.id === reopen; })) reopenVid = c.id;
                });
                if (reopenVid) flIssExpanded[reopenVid] = true;
            }
            /* 💬 Closed conversations are re-read for every card whose Chat is open, so one closed
                 a moment ago shows up without a page reload. */
            flIssClosedRows = {};
            flIssRender();
            Object.keys(flIssClosedOpen).forEach(function (vid) {
                if (flIssClosedOpen[vid] && document.getElementById('flIssChatRows' + vid)) flIssFetchClosed(vid);
            });
            if (reopen && reopenVid) flIssOpenTicket(reopen, reopenVid);
            // The tab's own red count, when this board is living inside the Bikes tab.
            if (typeof flIssBadgeFrom === 'function') flIssBadgeFrom(j.totals);
        })
        .catch(function () { /* a panel never breaks the page */ });
}

/**
 * ⚠⚠ NEVER RE-RENDER OVER SOMEBODY'S WORK. The poll refreshes the numbers, but if a thread is
 *    expanded or a reply is half-typed, redrawing the cards throws it away. This is the Daily
 *    Closing lesson (15-Sep, the same day): a live pane that wipes what the manager is doing is
 *    worse than one that updates a minute late.
 */
function flIssBusy() {
    if (flIssOpenThreadId) return true;
    var box = document.getElementById('flTicketReply');
    return !!(box && box.value && box.value.trim());
}

function flIssPoll() {
    if (flIssTimer) clearInterval(flIssTimer);
    flIssTimer = setInterval(function () {
        /* ⚠ Seen in the browser pane (15-Sep): a tab left hidden for a quarter of an hour had its
             throttled interval REPLAYED as a burst of 15 identical requests, 6 ms apart, the moment
             it was shown again. Nobody is reading a hidden tab; skip, and let the first visible
             tick refresh it. */
        if (document.hidden) return;
        if (document.getElementById('flIssWrap').style.display === 'none') return;
        if (flIssBusy()) { flIssLoadStripOnly(); return; }
        flIssLoad(true);
    }, 60000);
}

/* Only the counts, so a busy board still tells the truth about what is waiting. */
function flIssLoadStripOnly() {
    fetch('/orders/riders-map/fleet/issues?mode=live', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
            if (!j || !j.success) return;
            if (flIssData) flIssData.totals = j.totals;
            flIssRenderStrip();
            /**
             * ⭐ The tab's red count too (15-Sep, later). This is ALSO the call the Bikes tab makes
             *   when it first renders, before the board has ever been opened — the badge exists to
             *   say whether the tab is worth opening, and until this line it was 0 until you had
             *   already opened it, which is the one moment it could not help.
             */
            if (typeof flIssBadgeFrom === 'function') flIssBadgeFrom(j.totals);
        })
        .catch(function () {});
}

/**
 * ⭐⭐ REFRESH AFTER SOMETHING CHANGED ON THIS BOARD (15-Sep, later) — a booking, a reply, a close.
 *
 *    The plan said "after a booking, a reply or a close succeeds, the board refreshes"; only close
 *    did. Book a workshop from the board's own button and the card kept saying "Nothing booked";
 *    answer a rider in an inline thread and "waiting on us" stayed wrong — and the poll skips
 *    while a thread is open, so it stayed wrong until you closed the thread.
 *
 * ⚠ It never wipes an open thread: the thread's id is remembered, the cards are redrawn (its card
 *   forced OPEN, since cards now collapse), and the same thread is re-opened in its new box.
 * ⚠ Cheap and safe to call from shared code paths (the reply and booking tails are shared with
 *   the rider drawer and the vehicle panel): with no board loaded it only refreshes the counts.
 */
var flIssReopenTicket = null;
function flIssRefresh() {
    if (!flIssData) { flIssLoadStripOnly(); return; }
    flIssReopenTicket = flIssOpenThreadId;
    flIssOpenThreadId = null;
    flIssLoad(true);
}

function flIssSetFilter(key) {
    flIssFilter = key;
    if (key === 'closed') { flIssMode = 'history'; flIssLoad(); return; }
    if (flIssMode === 'history') { flIssMode = 'live'; flIssLoad(); return; }
    flIssRender();
}

function flIssRenderStrip() {
    var t = (flIssData && flIssData.totals) || {};
    var chip = function (key, label, n, cls) {
        if (!n && key !== 'all' && key !== 'closed') return '';
        return '<button type="button" class="fl-iss-chip ' + (cls || '')
             + (flIssFilter === key ? ' on' : '') + '" onclick="flIssSetFilter(\'' + key + '\')">'
             + label + (n === null ? '' : ' <b>' + n + '</b>') + '</button>';
    };
    document.getElementById('flIssStrip').innerHTML =
          chip('all', 'All', null)
        + chip('waiting_on_us', '⏱ Waiting on us', t.waiting_on_us || 0, 'amber')
        + chip('unanswered', '❓ Unanswered', t.unanswered || 0, 'red')
        + chip('urgent', '🔴 Not rideable', t.urgent || 0, 'red')
        + chip('workshop_missed', '❗ Missed', t.workshop_missed || 0, 'red')
        + chip('workshop_done_open', '🔧 Done, still open', t.workshop_done_open || 0, 'amber')
        + chip('proposed', '⏳ Awaiting approval', t.proposed || 0, 'purple')
        + chip('workshop_booked', '🔧 Booked', t.workshop_booked || 0, 'purple')
        + chip('closed', '✓ Closed', t.closed || 0);

    document.getElementById('flIssMeta').innerHTML =
        (t.machines || 0) + ' machines · ' + (t.with_issues || 0) + ' with issues · '
        + (t.quiet || 0) + ' quiet'
        + (flIssData && flIssData.read_only ? ' · <b>view only</b>' : '');
}

/** Does this card survive the chosen filter? Reads the SAME fields the strip counted. */
function flIssCardMatches(c) {
    var ts = c.open_tickets || [];
    switch (flIssFilter) {
        case 'all': return true;
        case 'urgent': return ts.some(function (t) { return t.urgent; });
        case 'unanswered': return ts.some(function (t) { return t.unanswered && t.age_hours >= 24; });
        case 'waiting_on_us': return ts.some(function (t) { return t.waiting_on === 'us' && t.waiting_hours >= 24; });
        case 'workshop_missed': return !!(c.workshop && c.workshop.is_missed);
        case 'proposed': return !!(c.workshop && c.workshop.is_proposed);
        case 'workshop_booked': return !!(c.workshop && !c.workshop.is_missed && !c.workshop.is_proposed);
        // ⚠ `!c.workshop` mirrors the server rule: a machine booked in again is not
        //   "done but still open" any more, and the chip must match the cards.
        case 'workshop_done_open': return !!(c.last_done_visit && ts.length && !c.workshop);
        default: return true;
    }
}

function flIssRender() {
    var wrap = document.getElementById('flIssCards');
    var quietBox = document.getElementById('flIssQuiet');
    flIssRenderStrip();
    if (!flIssData) { wrap.innerHTML = '<div class="fl-iss-empty">Loading…</div>'; return; }

    if (flIssData.available === false) {
        wrap.innerHTML = '<div class="fl-iss-empty">The bike ticket tables are not set up on this '
            + 'server yet. Nothing else on this page is affected.</div>';
        quietBox.innerHTML = '';
        return;
    }

    if (flIssMode === 'history') {
        wrap.innerHTML = flIssRenderHistory(flIssData.history || []);
        quietBox.innerHTML = '';
        return;
    }

    var cards = (flIssData.vehicles || []).filter(flIssCardMatches);
    wrap.innerHTML = cards.length
        ? cards.map(flIssCardHtml).join('')
        : '<div class="fl-iss-empty">'
          + ((flIssData.vehicles || []).length
               ? 'Nothing matches that filter. <a href="#" onclick="flIssSetFilter(\'all\');return false;">Show all</a>'
               : 'Nothing open on any machine.'
                 + ((flIssData.totals || {}).closed
                      ? ' ' + flIssData.totals.closed + ' closed — <a href="#" onclick="flIssSetFilter(\'closed\');return false;">show history</a>.'
                      : ''))
          + '</div>';

    var q = flIssData.quiet || [];
    quietBox.innerHTML = q.length
        ? '<div class="fl-iss-quiet">🟢 <b>' + q.length + '</b> machine' + (q.length === 1 ? '' : 's')
          + ' with nothing open: '
          + q.map(function (v) {
                /* 💬 A quiet machine can still have a history. The count is the way in: it opens
                     the machine's own panel, whose "Tickets & chat" block lists every conversation. */
                return '<a href="#" onclick="flIssOpenVehicle(' + v.id + ');return false;" '
                     + 'style="color:#4b5563;">' + flIssEsc(v.name || ('#' + v.id))
                     + (v.closed_count ? ' <span style="color:#2563eb;font-weight:700;">💬' + v.closed_count + '</span>' : '')
                     + '</a>';
            }).join(' · ')
          + '</div>'
        : '';
}

/** The machine's own page — the Bikes tab opens it in place, the standalone page links to it. */
function flIssOpenVehicle(id) {
    if (window.FL_ISS_INLINE_THREADS && typeof flvOpen === 'function') {
        if (typeof flSetMode === 'function') flSetMode('vehicles');
        setTimeout(function () { flvOpen(id); }, 60);
        return;
    }
    window.location.href = '/riders-map#bikes?vehicle=' + encodeURIComponent(id);
}

function flIssTag(text, bg, fg) {
    return '<span class="fl-iss-tag" style="background:' + bg + ';color:' + fg + ';">' + text + '</span>';
}

/**
 * ⚠ A RED machine opens itself — "not rideable", a missed workshop day or a complaint nobody has
 *   answered must never sit behind a click, or the stripe shouts while the card hides why.
 * ⚠ So does every card while a FILTER is on: clicking "⏱ On us" IS the drill-in, and making him
 *   click twice for one answer is exactly what he was trying to avoid.
 */
function flIssAutoOpen(c) {
    return (c.attention && c.attention.level === 'red') || flIssFilter !== 'all';
}
function flIssIsOpen(c) {
    return flIssExpanded[c.id] === undefined ? flIssAutoOpen(c) : !!flIssExpanded[c.id];
}

/**
 * ⚠⚠ TOGGLED IN THE DOM, NOT BY RE-RENDERING. `flIssRender()` rebuilds every card's innerHTML,
 *    which would wipe an inline thread a manager has open in ANOTHER card — the same reason the
 *    poll refreshes only the strip. Flipping one container's display costs nothing and loses
 *    nothing.
 */
function flIssToggleCard(id) {
    var body = document.getElementById('flIssBody' + id);
    if (!body) return;
    var open = body.style.display === 'none';
    body.style.display = open ? '' : 'none';
    flIssExpanded[id] = open;
    var chev = document.getElementById('flIssChev' + id);
    if (chev) chev.textContent = open ? '▾' : '▸';
}

function flIssCardHtml(c) {
    var ts = c.open_tickets || [];
    var tags = '';
    if (ts.some(function (t) { return t.urgent; })) tags += flIssTag('🔴 Not rideable', '#fef2f2', '#b91c1c');
    if (ts.length) tags += flIssTag('🎫 ' + ts.length + ' open', '#fffbeb', '#b45309');
    tags += flIssWorkshopTag(c);

    var rows = ts.map(function (t) { return flIssRowHtml(c, t); }).join('');
    /**
     * 💬 CHAT (owner, 15-Sep): *"a button called chat on clicking which it should show the previous
     *    chat details… more explicit for users to understand."* The old footer read "✓ 5 closed ·
     *    Show ›" — a COUNT, which nobody clicks looking for what was said. The button counts every
     *    conversation on the machine, open and closed, and expands the earlier (closed) ones under
     *    a divider; the open ones are already the rows above. Each row carries the ONE context line
     *    the server composes, so the reader knows what it was for, when, and how it ended.
     * ⚠ Same words, same rows, same thread renderer as the history chip — nothing new to maintain.
     */
    var convs = ts.length + (c.closed_count || 0);
    var chatBtn = convs
        ? '<div class="fl-iss-foot"><button type="button" class="fl-vchipbtn" '
          + 'onclick="flIssToggleClosed(' + c.id + ')">💬 Chat (' + convs + ')'
          + '<span id="flIssChatChev' + c.id + '">' + (flIssClosedOpen[c.id] ? ' ▾' : ' ▸') + '</span></button>'
          + (c.last_closed_at ? '<span>last closed ' + flIssEsc(String(c.last_closed_at).substring(0, 10)) + '</span>' : '')
          + '</div>'
        : '';
    /**
     * ⚠⚠ ALWAYS IN THE DOM, shown or hidden — never re-rendered into existence. The first cut
     *    re-drew every card on each Chat press, which wiped a thread a manager had open in
     *    ANOTHER card and left `flIssOpenThreadId` pointing at a box that no longer existed (so
     *    the poll believed a thread was open forever). The toggle now flips this one container,
     *    exactly as the card collapse does, and the rows are fetched INTO it.
     */
    var closed = convs
        ? '<div id="flIssChat' + c.id + '"' + (flIssClosedOpen[c.id] ? '' : ' style="display:none;"') + '>'
          + (c.closed_count
              ? '<div class="fl-iss-closed" style="color:#6b7280;font-weight:700;">Earlier conversations, closed</div>'
                + '<div id="flIssChatRows' + c.id + '">'
                + (flIssClosedRows[c.id] || '<div class="fl-iss-closed">Loading…</div>') + '</div>'
              : '<div class="fl-iss-closed">Nothing older on this machine — the open conversations are the rows above; click one to read it.</div>')
          + '</div>'
        : '';

    var open = flIssIsOpen(c);
    /**
     * ⚠ Unread messages are summed onto the HEADER, because they are the one thing collapsing
     *   would otherwise hide — a per-row badge nobody can see is a badge that has stopped
     *   working. A summary reader gets 0 throughout by construction, so it never shows for him.
     */
    var unread = ts.reduce(function (a, t) { return a + (t.unread || 0); }, 0);

    return '<div class="fl-iss-card ' + flIssEsc(c.attention.level) + '">'
        /* ⚠ The HEAD toggles. The plate used to navigate away, which is the wrong default on a
             board you are scanning — the machine's panel is now an explicit link in the body. */
        + '<div class="fl-iss-hd" onclick="flIssToggleCard(' + c.id + ')" style="cursor:pointer;">'
        +   '<span class="fl-iss-plate">' + flIssEsc(c.name || ('#' + c.id)) + '</span>'
        +   '<span class="fl-iss-who">' + flIssEsc(c.keeper_name || 'nobody holds it')
        +     (c.is_company ? '' : ' · own bike') + (c.is_active ? '' : ' · retired') + '</span>'
        +   (unread ? '<span style="background:#dc2626;color:#fff;font-size:10px;font-weight:800;'
                    + 'border-radius:999px;padding:1px 6px;">' + unread + '</span>' : '')
        +   '<span class="fl-iss-tags">' + tags
        +     '<span id="flIssChev' + c.id + '" style="color:#9ca3af;font-weight:700;font-size:12px;">'
        +     (open ? '▾' : '▸') + '</span></span>'
        + '</div>'
        + '<div class="fl-iss-attn" onclick="flIssToggleCard(' + c.id + ')" style="cursor:pointer;">'
        +   flIssEsc(c.attention.line) + '</div>'
        + '<div id="flIssBody' + c.id + '"' + (open ? '' : ' style="display:none;"') + '>'
        +   rows
        +   flIssWorkshopHtml(c)
        /* ⚠ The machine's own panel, explicit and labelled, now that the header is the toggle. */
        +   '<div style="padding:0 12px 9px;"><a href="#" style="color:#2563eb;font-size:11.5px;'
        +     'font-weight:700;" onclick="flIssOpenVehicle(' + c.id + ');return false;">'
        +     'Open this bike ›</a></div>'
        +   chatBtn
        +   closed
        + '</div>'
        + '</div>';
}

function flIssWorkshopTag(c) {
    var w = c.workshop;
    if (!w) return c.open_tickets && c.open_tickets.length
        ? flIssTag('🔧 Nothing booked', '#f3f4f6', '#6b7280') : '';
    if (w.is_missed)   return flIssTag('❗ Missed', '#fef2f2', '#b91c1c');
    if (w.is_proposed) return flIssTag('⏳ Awaiting approval', '#ede9fe', '#5b21b6');
    if (w.is_today)    return flIssTag('🔧 Today' + (w.accepted ? ' ✓✓' : ' ✓'), '#ede9fe', '#5b21b6');
    if (w.is_tomorrow) return flIssTag('🔧 Tomorrow' + (w.accepted ? ' ✓✓' : ' ✓'), '#ede9fe', '#5b21b6');
    return flIssTag('🔧 ' + flIssEsc(w.visit_date), '#ede9fe', '#5b21b6');
}

/**
 * The workshop line. ⚠ Actions are NOT duplicated here: approving, declining, marking done and
 * cancelling all live on the machine's own panel, which is one click away. The board says WHAT
 * is true; the panel is where it is changed. The one exception is Schedule, because "book it in"
 * is the commonest ANSWER to a fault and making a manager navigate to give it loses the thread.
 */
function flIssWorkshopHtml(c) {
    var w = c.workshop, d = c.last_done_visit;
    var can = flIssData && flIssData.can_schedule;

    /**
     * ⚠⚠ THE BUTTON KEYS OFF "NO LIVE VISIT", NOT "NOTHING AT ALL" (found by the harness).
     *    The first cut hid Schedule whenever there was anything to print — so the one machine
     *    that most needs booking in, the one whose last visit is DONE while its complaint is
     *    still open, was the only machine on the board with no way to book it. That card is the
     *    whole point of the "nobody is closing these" detector, and it was the dead end.
     */
    var scheduleBtn = (!w && can && (c.open_tickets || []).length)
        ? '<span style="margin-left:auto;"><button type="button" class="fl-vchipbtn" '
          + 'onclick="flIssSchedule(' + c.id + ',' + flIssArg(c.keeper_name || '') + ',' + (c.keeper_user_id || 'null') + ')">'
          + '🔧 Schedule workshop</button></span>'
        : '';

    if (!w && !d) {
        return scheduleBtn
            ? '<div class="fl-iss-ws"><span>🔧</span><span style="color:#9ca3af;">Nothing booked</span>'
              + scheduleBtn + '</div>'
            : '';
    }
    var line = '';
    if (w) {
        line = '🔧 <b>' + flIssEsc(w.visit_date) + (w.visit_time ? ' ' + flIssEsc(w.visit_time) : '') + '</b> · '
             + flIssEsc(w.rider_name || c.keeper_name || 'rider') + ' takes it'
             + (w.workshop_label ? ' · ' + flIssEsc(w.workshop_label) : '')
             + ' · ' + (w.is_proposed ? '⏳ awaiting a shift planner — rider NOT told'
                         : (w.accepted ? '✓✓ accepted' : '✓ scheduled, rider told'))
             /* 🔗 PART B: WHAT the day is for. Before this, a visit named no issue at all and a
                manager reading a card could not tell whether the trip answered the complaint
                above it or something else entirely. */
             + flIssCovers(w);
    } else {
        /* ⭐ THE "NOBODY CLOSED IT" LINE — the owner's own words. The work happened and the
             conversation was never finished. */
        line = '🔧 Done ' + flIssEsc(d.visit_date)
             + (d.workshop_label ? ' · ' + flIssEsc(d.workshop_label) : '')
             + ' · ' + (d.outcome_note ? flIssEsc(d.outcome_note) : '<i>no outcome note</i>')
             + flIssCovers(d);
    }
    /* ⚠ 15-Sep: the "open the machine ›" fallback that used to sit here is gone — the card body
         now carries one explicit "Open this bike ›" link for every card, and two identical links
         on the same card is one too many. */
    return '<div class="fl-iss-ws"><span>' + line + '</span>' + scheduleBtn + '</div>';
}

/**
 * 🔗 PART B: "covers: tyres, seat" — which reported issues a visit is answering.
 * ⚠ Names them rather than counting them. "Covers 2 issues" next to a list of four is a riddle;
 *   the titles are short and they are the whole point of the link.
 */
function flIssCovers(v) {
    const ts = (v && v.tickets) || [];
    if (!ts.length) return '';
    const names = ts.slice(0, 3).map(t => flIssEsc(t.title)).join(', ');
    return '<br><span style="color:#6b7280;font-size:11.5px;">covers: ' + names
         + (ts.length > 3 ? ' +' + (ts.length - 3) + ' more' : '') + '</span>';
}

function flIssRowHtml(c, t) {
    var turnClass = t.waiting_on === 'us' ? '#b45309' : (t.waiting_on === 'rider' ? '#1d4ed8' : '#5b21b6');
    var turnText = t.unanswered && !t.last_message ? 'nobody has replied'
        : (t.waiting_on === 'us' ? 'waiting on us'
           : (t.waiting_on === 'rider' ? 'waiting on ' + flIssEsc((c.keeper_name || 'him').split(' ')[0])
              : 'workshop set'));
    var m = t.last_message;
    var clickable = flIssData && flIssData.threads;

    /* ⭐ Close FROM the board (owner ruling, 15-Sep) — and it calls the SAME flCloseTicket()
         the thread and the vehicle panel call, so there is one close engine, one dialog and one
         note field wherever it is pressed. */
    var canClose = flIssData && flIssData.can_manage && t.is_open;
    var closeBtn = canClose
        ? '<button type="button" class="fl-vchipbtn" style="margin-left:6px;" '
          + 'onclick="event.stopPropagation();flIssClose(' + t.id + ',' + (t.unread || 0) + ')">Close</button>'
        : '';

    return '<div class="fl-iss-row' + (clickable ? ' clickable' : '') + '"'
        + (clickable ? ' onclick="flIssOpenTicket(' + t.id + ',' + c.id + ')"' : '') + '>'
        + '<div class="fl-iss-rowtop">'
        +   '<span class="fl-iss-title">' + (t.urgent ? '🔴 ' : '') + flIssEsc(t.title) + '</span>'
        +   (t.unread ? '<span style="background:#dc2626;color:#fff;font-size:10px;font-weight:800;'
                      + 'border-radius:999px;padding:1px 6px;">' + t.unread + '</span>' : '')
        +   flIssStatusTag(t.status)
        +   '<span class="fl-iss-age">' + flIssAge(t.age_hours) + '</span>'
        +   closeBtn
        + '</div>'
        + '<div class="fl-iss-sub">'
        +   '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
        +     (m ? flIssEsc(m.author_name || 'system') + ': ' + flIssEsc(m.snippet)
                   + ' · ' + flIssEsc(String(m.created_at || '').substring(0, 16))
                 : 'no messages')
        +   '</span>'
        +   '<span class="fl-iss-turn" style="color:' + turnClass + ';">' + turnText + '</span>'
        + '</div>'
        + '<div id="flIssThread' + t.id + '"></div>'
        + '</div>';
}

function flIssStatusTag(s) {
    var m = { open: ['#fef3c7', '#92400e', 'Open'], acknowledged: ['#dbeafe', '#1e40af', 'In progress'],
              scheduled: ['#ede9fe', '#5b21b6', 'Workshop set'], closed: ['#e5e7eb', '#4b5563', 'Closed'] };
    var v = m[s] || m.open;
    return flIssTag(v[2], v[0], v[1]);
}

function flIssAge(h) {
    if (!h) return 'today';
    if (h < 48) return h + 'h';
    return Math.floor(h / 24) + 'd';
}

/**
 * Open a thread from the board.
 * ⚠ NO SECOND THREAD RENDERER. Inside the Bikes tab this hands straight to flOpenTicket(), the
 *   one that already draws messages, photos, voice notes and the composer. On the standalone
 *   page there is no such renderer, so the board LINKS into Bikes rather than growing one.
 */
function flIssOpenTicket(ticketId, vehicleId) {
    if (!window.FL_ISS_INLINE_THREADS || typeof flOpenTicket !== 'function') {
        window.location.href = '/riders-map#bikes?vehicle=' + encodeURIComponent(vehicleId)
                             + '&ticket=' + encodeURIComponent(ticketId);
        return;
    }
    var wrap = document.getElementById('flIssThread' + ticketId);
    if (!wrap) return;
    if (flIssOpenThreadId === ticketId) {      // a second tap closes it
        wrap.innerHTML = '';
        flIssOpenThreadId = null;
        return;
    }
    if (flIssOpenThreadId) {
        var old = document.getElementById('flIssThread' + flIssOpenThreadId);
        if (old) old.innerHTML = '';
    }
    flIssOpenThreadId = ticketId;
    flOpenTicket(ticketId, wrap);
}

/**
 * ⭐⭐ ONE CLOSE ENGINE (owner condition, 15-Sep: "only if it follows the same single engine
 *    approach, so whether done from here or from inside the vehicles tab it should work
 *    seamlessly"). This does NOT post anything — it calls the dialog the vehicle panel and the
 *    thread already use, which posts to the one endpoint and then refreshes all three surfaces.
 *
 * ⚠ The one thing the board adds is a warning. The button has moved OFF the thread, so a manager
 *   can now close an issue whose newest messages he has not read. It warns; it never blocks.
 */
function flIssClose(ticketId, unread) {
    if (typeof flCloseTicket !== 'function') {
        window.location.href = '/riders-map#bikes?ticket=' + encodeURIComponent(ticketId);
        return;
    }
    flCloseTicket(ticketId, unread);
}

function flIssSchedule(vehicleId, keeperName, keeperUserId) {
    if (typeof flScheduleWorkshop !== 'function') {
        window.location.href = '/riders-map#bikes?vehicle=' + encodeURIComponent(vehicleId);
        return;
    }
    flScheduleWorkshop(keeperUserId || null, keeperName || null, vehicleId);
}

/* Closed rows per machine, fetched once and kept — history is history, it does not move. */
var flIssClosedRows = {};
/* DOM-only, like flIssToggleCard — see the note in flIssCardHtml. */
function flIssToggleClosed(vehicleId) {
    var open = !flIssClosedOpen[vehicleId];
    flIssClosedOpen[vehicleId] = open;
    var box = document.getElementById('flIssChat' + vehicleId);
    if (box) box.style.display = open ? '' : 'none';
    var chev = document.getElementById('flIssChatChev' + vehicleId);
    if (chev) chev.textContent = open ? ' ▾' : ' ▸';
    if (open && !flIssClosedRows[vehicleId]) flIssFetchClosed(vehicleId);
}

/**
 * The machine's closed conversations, written INTO the card's own box.
 * ⚠ The cache is dropped on every board load (see flIssLoad): a conversation closed a moment ago
 *   must appear under Chat without a page reload — the first cut cached it for the page's life.
 */
function flIssFetchClosed(vehicleId) {
    fetch('/orders/riders-map/fleet/issues?mode=history&vehicle_id=' + encodeURIComponent(vehicleId),
          { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
            if (!j || !j.success) return;
            flIssClosedRows[vehicleId] = (j.history || []).map(flIssClosedRowHtml).join('')
                || '<div class="fl-iss-closed">Nothing closed on this machine.</div>';
            var rows = document.getElementById('flIssChatRows' + vehicleId);
            if (rows) rows.innerHTML = flIssClosedRows[vehicleId];
        })
        .catch(function () {});
}

function flIssClosedRowHtml(t) {
    var clickable = flIssData && flIssData.threads;
    return '<div class="fl-iss-closed"' + (clickable
            ? ' style="cursor:pointer;" onclick="flIssOpenTicket(' + t.id + ',' + t.vehicle_id + ')"' : '') + '>'
        + '<span class="t">' + flIssEsc(t.title) + '</span>'
        + '<div style="font-size:11.5px;margin-top:2px;">'
        +   flIssEsc(t.context_line || ('closed ' + String(t.closed_at || '').substring(0, 10)))
        +   ' · <b>' + (t.message_count || 0) + '</b> message' + (t.message_count === 1 ? '' : 's')
        + '</div>'
        + '<div id="flIssThread' + t.id + '"></div>'
        + '</div>';
}

function flIssRenderHistory(rows) {
    if (!rows.length) return '<div class="fl-iss-empty">Nothing has been closed yet.</div>';
    return '<div style="grid-column:1/-1;">'
        + '<div style="font-size:12px;color:#6b7280;margin-bottom:8px;">'
        + 'Closed issues, newest first. Everything here is finished — '
        + '<a href="#" onclick="flIssSetFilter(\'all\');return false;">back to what is open</a>.</div>'
        + rows.map(function (t) {
            /* 💬 15-Sep (later): the row itself opens the conversation — this was the one
                 place on the web board where a closed thread could not be reached. */
            var clickable = flIssData && flIssData.threads;
            return '<div class="fl-iss-card grey" style="margin-bottom:8px;opacity:.85;'
                + (clickable ? 'cursor:pointer;" onclick="flIssOpenTicket(' + t.id + ',' + t.vehicle_id + ')' : '') + '">'
                + '<div class="fl-iss-hd">'
                +   '<span class="fl-iss-plate" style="border-color:#9ca3af;color:#6b7280;" '
                +     'onclick="event.stopPropagation();flIssOpenVehicle(' + t.vehicle_id + ')">'
                +     flIssEsc(t.vehicle_name || ('#' + t.vehicle_id)) + '</span>'
                +   '<span class="fl-iss-who" style="text-decoration:line-through;">' + flIssEsc(t.title) + '</span>'
                +   '<span class="fl-iss-tags">' + flIssStatusTag('closed') + '</span>'
                + '</div>'
                + '<div class="fl-iss-sub" style="padding:0 12px 9px;">'
                +   '<span>' + flIssEsc(t.context_line || '') + '</span>'
                +   '<span><b>' + (t.message_count || 0) + '</b> message' + (t.message_count === 1 ? '' : 's') + '</span>'
                + '</div>'
                + '<div id="flIssThread' + t.id + '"></div>'
                + '</div>';
        }).join('')
        + '</div>';
}
</script>
