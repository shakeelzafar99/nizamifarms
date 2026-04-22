@extends('layouts.app')

@section('title', 'Messages')

@push('demo1_css')
<style>
/* ══════════════════════════════════════════════════════════════
   WhatsApp-style Messages Page
   ══════════════════════════════════════════════════════════════ */

/* Override the layout so content fills height */
#content { display: flex; flex-direction: column; overflow: hidden; }

/* ── Main Container ── */
.wa-page {
    display: flex;
    flex: 1;
    height: calc(100vh - 130px);
    background: #f0f2f5;
    border: 1px solid #dfe5e7;
    border-radius: 4px;
    overflow: hidden;
    margin: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* ── LEFT: Sidebar ── */
.wa-sidebar {
    width: 380px;
    min-width: 340px;
    max-width: 420px;
    background: #fff;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}

/* Sidebar Header */
.wa-side-hdr {
    padding: 12px 16px;
    background: #f0f2f5;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.wa-side-hdr-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.wa-side-hdr-top h2 {
    font-size: 20px;
    font-weight: 800;
    color: #111827;
    margin: 0;
}
.wa-side-hdr-actions {
    display: flex;
    gap: 6px;
}
.wa-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.15s;
}
.wa-btn-green { background: #16a34a; color: #fff; }
.wa-btn-green:hover { background: #15803d; }
.wa-btn-gray { background: #e5e7eb; color: #6b7280; }
.wa-btn-gray:hover { background: #d1d5db; }

/* Search */
.wa-search-wrap { position: relative; }
.wa-search-wrap svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}
.wa-search {
    width: 100%;
    padding: 9px 14px 9px 36px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    outline: none;
    background: #fff;
    box-sizing: border-box;
    transition: all 0.2s;
}
.wa-search:focus {
    border-color: #16a34a;
    box-shadow: 0 0 0 3px rgba(22,163,74,0.08);
}

/* Filters */
.wa-filters { display: flex; gap: 6px; margin-top: 10px; }
.wa-filter-btn {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    border: 1.5px solid transparent;
    cursor: pointer;
    background: #fff;
    color: #6b7280;
    transition: all 0.15s;
}
.wa-filter-btn:hover { background: #f3f4f6; }
.wa-filter-btn.active {
    background: #dcfce7;
    color: #16a34a;
    border-color: #86efac;
}

/* Search-mode toggle (Names / Chats). Minimal, pill-shaped, picks up
   the same green accent as filters when active so the UI stays cohesive. */
.wa-searchmode-btn {
    flex: 1;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.15s;
}
.wa-searchmode-btn:hover { background: #f9fafb; }
.wa-searchmode-btn.active {
    background: #eef2ff;
    color: #4338ca;
    border-color: #c7d2fe;
}

/* Chat-content match snippet shown under a conversation when the
   sidebar is in "Chats" search mode. Slight indigo tint so it reads as
   "matched content" rather than "latest message". */
.wa-conv-match {
    font-size: 11.5px;
    color: #4b5563;
    background: #eef2ff;
    border-left: 2px solid #6366f1;
    padding: 3px 6px;
    border-radius: 4px;
    margin-top: 3px;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-conv-match mark {
    background: #fde68a;
    color: #78350f;
    padding: 0 2px;
    border-radius: 2px;
}

/* New Message Panel */
.wa-new-panel {
    display: none;
    padding: 12px 16px;
    border-bottom: 1px solid #d1fae5;
    background: #f0fdf4;
    flex-shrink: 0;
}
.wa-new-panel.open { display: block; }
.wa-new-panel-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.wa-new-panel-hdr span { font-size: 13px; font-weight: 600; color: #166534; }
.wa-new-panel-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #6b7280;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 4px;
}
.wa-new-panel-close:hover { background: #dcfce7; }
.wa-new-input {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid #bbf7d0;
    border-radius: 8px;
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
    background: #fff;
}
.wa-new-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.08); }
.wa-cust-results {
    max-height: 240px;
    overflow-y: auto;
    margin-top: 6px;
}
.wa-cust-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    cursor: pointer;
    border-radius: 8px;
    transition: background 0.1s;
}
.wa-cust-item:hover { background: #dcfce7; }
.wa-cust-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}
.wa-cust-info { flex: 1; min-width: 0; }
.wa-cust-name { font-size: 14px; font-weight: 500; color: #111827; }
.wa-cust-phone { font-size: 12px; color: #6b7280; }

/* Conversation List */
.wa-conv-list {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db transparent;
}
.wa-conv-list::-webkit-scrollbar { width: 5px; }
.wa-conv-list::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
.wa-conv-list::-webkit-scrollbar-track { background: transparent; }
.wa-conv-item {
    display: flex;
    align-items: flex-start;
    padding: 13px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.12s;
}
.wa-conv-item:hover { background: #f3f4f6; }
.wa-conv-item.active { background: #dcfce7; }
.wa-conv-item.unread { background: #f0fdf4; }
.wa-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 17px;
    flex-shrink: 0;
    margin-right: 12px;
}
.wa-conv-info { flex: 1; min-width: 0; }
.wa-conv-top { display: flex; justify-content: space-between; align-items: center; }
.wa-conv-name {
    font-size: 14px;
    font-weight: 500;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-conv-item.unread .wa-conv-name { font-weight: 700; }
.wa-conv-time { font-size: 11px; color: #9ca3af; flex-shrink: 0; margin-left: 8px; }
.wa-conv-item.unread .wa-conv-time { color: #16a34a; font-weight: 600; }
.wa-conv-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 3px; }
.wa-conv-preview {
    font-size: 13px;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}
.wa-conv-item.unread .wa-conv-preview { font-weight: 600; color: #374151; }
.wa-conv-city { font-size: 11px; color: #9ca3af; margin-top: 2px; }

/* ── Labels: shared chip styles + inbox-row strip + header strip ───────
   We render labels as pills both on inbox rows (small, inline, max two
   visible plus "+N more") and in the chat header (slightly larger so
   the operator can scan the current status at a glance). Colour comes
   from the label record itself; we pick a readable text colour based
   on a contrast heuristic at render time.
*/
.wa-label-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.1px;
    line-height: 1.4;
    white-space: nowrap;
    background: #e5e7eb;
    color: #111827;
}
.wa-label-chip .wa-label-remove {
    background: none;
    border: none;
    color: inherit;
    padding: 0 0 0 2px;
    font-size: 12px;
    cursor: pointer;
    opacity: 0.75;
    line-height: 1;
}
.wa-label-chip .wa-label-remove:hover { opacity: 1; }
.wa-conv-labels { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.wa-chat-hdr-labels {
    display: flex; gap: 6px; flex-wrap: wrap;
    padding: 6px 12px 0 12px;
    background: rgba(0,0,0,0.04);
}
.wa-chat-hdr-labels:empty { display: none; }

/* 3-dot chat-header menu trigger + dropdown. Absolute-positioned
   dropdown so it floats over the chat body instead of getting clipped
   by overflow:hidden on .wa-chat. */
.wa-chat-menu-wrap { position: relative; margin-left: 6px; }
.wa-chat-menu-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    border-radius: 6px;
    padding: 6px 10px;
    cursor: pointer;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
}
.wa-chat-menu-btn:hover { background: rgba(255,255,255,0.32); }
.wa-chat-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    min-width: 200px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    z-index: 20;
    overflow: hidden;
}
.wa-chat-menu.open { display: block; }
.wa-chat-menu button {
    display: flex; align-items: center; gap: 8px;
    width: 100%;
    padding: 10px 14px;
    background: #fff;
    border: none;
    text-align: left;
    font-size: 13px;
    color: #111827;
    cursor: pointer;
}
.wa-chat-menu button:hover { background: #f3f4f6; }
.wa-chat-menu hr { margin: 4px 0; border: none; border-top: 1px solid #f3f4f6; }

/* Labels modal */
.wa-labels-modal {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.wa-labels-modal.open { display: flex; }
.wa-labels-box {
    width: 480px; max-width: 95vw; max-height: 88vh;
    background: #fff; border-radius: 12px;
    display: flex; flex-direction: column;
    overflow: hidden;
}
.wa-labels-hdr {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid #f3f4f6;
}
.wa-labels-hdr h3 { margin: 0; font-size: 15px; font-weight: 700; color: #111827; }
.wa-labels-body { padding: 14px 18px; overflow-y: auto; flex: 1; }
.wa-labels-section-title {
    font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px;
    color: #6b7280; font-weight: 700;
    margin: 4px 0 8px 0;
}
.wa-label-toggle {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px;
    border-radius: 12px;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
    border: 1.5px solid #e5e7eb;
    background: #fff;
    color: #374151;
    margin: 3px 4px 3px 0;
    transition: all 0.12s;
}
.wa-label-toggle.applied {
    border-color: currentColor;
}
.wa-label-toggle:hover { background: #f9fafb; }
.wa-label-manage-row {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 0;
    border-bottom: 1px dashed #f3f4f6;
}
.wa-label-manage-row:last-child { border-bottom: none; }
.wa-label-color-dot {
    width: 14px; height: 14px; border-radius: 50%;
    flex-shrink: 0;
    border: 1px solid rgba(0,0,0,0.1);
}
.wa-label-manage-actions { margin-left: auto; display: flex; gap: 4px; }
.wa-label-manage-actions button {
    background: none; border: none; cursor: pointer;
    color: #6b7280; padding: 4px 6px; border-radius: 6px; font-size: 12px;
}
.wa-label-manage-actions button:hover { color: #111827; background: #f3f4f6; }
.wa-label-create-row {
    display: flex; gap: 6px; align-items: center;
    padding-top: 10px; border-top: 1px solid #f3f4f6; margin-top: 10px;
}
.wa-label-create-row input[type=text] {
    flex: 1; padding: 7px 10px;
    border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; outline: none;
}
.wa-label-create-row input[type=color] {
    width: 36px; height: 34px; padding: 2px;
    border: 1.5px solid #e5e7eb; border-radius: 8px; cursor: pointer;
    background: #fff;
}
.wa-label-create-row button {
    padding: 7px 14px;
    background: #16a34a; color: #fff; font-weight: 600; font-size: 13px;
    border: none; border-radius: 8px; cursor: pointer;
}
.wa-label-create-row button:hover { background: #15803d; }

/* Labels filter dropdown on the inbox */
.wa-label-filter-wrap { position: relative; margin-left: auto; }
.wa-label-filter-btn {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 12px;
    cursor: pointer;
    color: #374151;
    font-weight: 500;
}
.wa-label-filter-btn:hover { background: #f3f4f6; }
.wa-label-filter-btn.active { background: #dcfce7; border-color: #86efac; color: #166534; }
.wa-label-filter-menu {
    display: none;
    position: absolute; right: 0; top: calc(100% + 4px);
    min-width: 180px; max-height: 260px; overflow-y: auto;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    box-shadow: 0 8px 22px rgba(0,0,0,0.1);
    z-index: 30;
}
.wa-label-filter-menu.open { display: block; }
.wa-label-filter-menu button {
    display: flex; align-items: center; gap: 6px;
    width: 100%; padding: 8px 12px;
    background: #fff; border: none; text-align: left;
    font-size: 13px; cursor: pointer; color: #111827;
}
.wa-label-filter-menu button:hover { background: #f3f4f6; }
.wa-label-filter-menu button.active { background: #f0fdf4; font-weight: 600; }
.wa-unread-badge {
    background: #16a34a;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    border-radius: 10px;
    min-width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    margin-left: 8px;
}

/* Phase 2 — @mention dot on inbox rows. Blue to differentiate from the
   green "unread" badge; shown only when the CURRENT user has an unread
   mention on this conversation. */
.wa-mention-dot {
    background: #3B82F6;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 10px;
    min-width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    margin-left: 6px;
    line-height: 1;
}
.wa-filter-me { display: inline-flex; align-items: center; gap: 4px; }
.wa-filter-me .wa-mention-pip {
    width: 8px; height: 8px; border-radius: 50%; background: #DC2626;
    display: inline-block;
}
/* User-mention label: subtle @ tag on the toggle chip. */
.wa-label-mention-tag {
    color: #3B82F6; font-weight: 700; margin-right: 2px;
}
.wa-label-mention-badge {
    font-size: 10px; color: #3B82F6; font-weight: 600;
    background: #EFF6FF; border: 1px solid #BFDBFE;
    padding: 1px 5px; border-radius: 6px; margin-left: 4px;
}
/* User-picker dropdown in the create-label row. */
.wa-label-user-select {
    flex: 1; min-width: 140px;
    padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 6px;
    font-size: 12px; background: #fff;
}

/* ── RIGHT: Chat Panel ── */
.wa-chat {
    flex: 1;
    display: none;
    flex-direction: column;
    min-width: 0;
    background: #e5ddd5;
    background-image: url("data:image/svg+xml,%3Csvg width='400' height='400' xmlns='http://www.w3.org/2000/svg'%3E%3Cdefs%3E%3Cpattern id='p' width='40' height='40' patternUnits='userSpaceOnUse'%3E%3Cpath d='M20 0v10M0 20h10M30 20h10M20 30v10' stroke='%23c9c2b6' stroke-width='0.5' fill='none' opacity='0.4'/%3E%3Ccircle cx='20' cy='20' r='1' fill='%23c9c2b6' opacity='0.25'/%3E%3C/pattern%3E%3C/defs%3E%3Crect width='400' height='400' fill='%23e5ddd5'/%3E%3Crect width='400' height='400' fill='url(%23p)'/%3E%3C/svg%3E");
}
.wa-chat.visible { display: flex; }

/* Chat Header */
.wa-chat-hdr {
    padding: 10px 20px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    z-index: 3;
}
.wa-chat-back {
    cursor: pointer;
    font-size: 18px;
    color: rgba(255,255,255,0.9);
    background: rgba(255,255,255,0.12);
    border: none;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    display: none;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-chat-back:hover { background: rgba(255,255,255,0.22); }
.wa-chat-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}
.wa-chat-hdr-info { flex: 1; min-width: 0; }
.wa-chat-hdr-info h3 {
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-chat-hdr-info p {
    color: rgba(255,255,255,0.8);
    font-size: 12px;
    margin: 1px 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-session-badge {
    background: rgba(255,255,255,0.18);
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 11px;
    color: #fff;
    font-weight: 600;
    flex-shrink: 0;
}

/* Chat Messages */
.wa-chat-msgs {
    flex: 1;
    overflow-y: auto;
    padding: 16px 60px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    scrollbar-width: thin;
    scrollbar-color: #bbb transparent;
}
.wa-chat-msgs::-webkit-scrollbar { width: 6px; }
.wa-chat-msgs::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 10px; }

/* Message Bubbles */
.wa-msg {
    max-width: 55%;
    padding: 7px 10px 6px;
    border-radius: 10px;
    position: relative;
    box-shadow: 0 1px 1px rgba(0,0,0,0.06);
    word-break: break-word;
}
.wa-msg.out {
    align-self: flex-end;
    background: #d9fdd3;
    border-bottom-right-radius: 3px;
}
.wa-msg.in {
    align-self: flex-start;
    background: #fff;
    border-bottom-left-radius: 3px;
}
.wa-msg-text { font-size: 13.5px; line-height: 1.4; color: #111827; white-space: pre-wrap; }
.wa-msg-meta { display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 2px; }
.wa-msg-time { font-size: 10px; color: #6b7280; }
.wa-msg-sender { font-size: 10px; color: #6b7280; }
.wa-msg-status { font-size: 10px; color: #6b7280; }
.wa-msg-status.read { color: #3b82f6; }
.wa-msg-status.failed { color: #ef4444; }
.wa-msg-error { font-size: 11px; color: #ef4444; margin-top: 4px; font-style: italic; }
.wa-msg-tpl-badge {
    background: rgba(0,0,0,0.05);
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 10px;
    color: #6b7280;
    margin-bottom: 4px;
    display: inline-block;
}
.wa-msg-media {
    background: rgba(0,0,0,0.04);
    padding: 8px 10px;
    border-radius: 6px;
    margin-bottom: 4px;
    font-size: 13px;
    color: #374151;
}

/* Chat Input */
.wa-chat-foot {
    padding: 10px 60px;
    background: #f0f0f0;
    flex-shrink: 0;
    z-index: 3;
}
.wa-date-sep {
    text-align: center;
    margin: 16px 0 10px;
}
.wa-date-sep span {
    background: #e2e8f0;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 14px;
    border-radius: 8px;
    letter-spacing: 0.3px;
}
.wa-session-timer {
    font-size: 11px;
    color: #92400e;
    background: #fef3c7;
    padding: 5px 12px;
    border-radius: 6px;
    text-align: center;
    margin-bottom: 6px;
}
.wa-session-timer.expiring { color: #dc2626; background: #fee2e2; font-weight: 600; }
.wa-expired-bar {
    display: none;
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 1px solid #fcd34d;
    padding: 14px 16px;
    text-align: center;
    border-radius: 12px;
    margin-bottom: 10px;
}
.wa-expired-bar p { margin: 0 0 10px; font-size: 13px; color: #92400e; font-weight: 500; }
.wa-expired-bar button {
    background: #16a34a;
    color: #fff;
    border: none;
    padding: 9px 22px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 1px 4px rgba(22,163,74,0.3);
}
.wa-expired-bar button:hover { background: #15803d; }
.wa-input-row { display: flex; align-items: flex-end; gap: 8px; }
.wa-text-input {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 22px;
    font-size: 14px;
    outline: none;
    resize: none;
    max-height: 100px;
    font-family: inherit;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}
.wa-text-input:focus { box-shadow: 0 0 0 2px rgba(22,163,74,0.15); }
.wa-send-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #16a34a;
    border: none;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-send-btn:hover { background: #15803d; }
.wa-send-btn:disabled { background: #9ca3af; cursor: default; }
.wa-tpl-link { padding: 6px 0 0; }
.wa-tpl-link a {
    font-size: 12px;
    color: #16a34a;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
}
.wa-tpl-link a:hover { text-decoration: underline; }

/* ── RIGHT: Orders Panel ── */
.wa-orders-panel {
    width: 0;
    min-width: 0;
    overflow: hidden;
    background: #fff;
    border-left: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    transition: width 0.25s ease, min-width 0.25s ease;
    flex-shrink: 0;
}
.wa-orders-panel.open {
    width: 300px;
    min-width: 300px;
}
.wa-op-hdr {
    padding: 10px 14px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    background: #fafafa;
}
.wa-op-hdr h4 { font-size: 13px; font-weight: 700; color: #111827; margin: 0; }
.wa-op-close { background: none; border: none; font-size: 18px; cursor: pointer; color: #6b7280; padding: 2px 6px; border-radius: 4px; }
.wa-op-close:hover { background: #f3f4f6; }
.wa-op-list {
    flex: 1;
    overflow-y: auto;
    padding: 0;
    scrollbar-width: thin;
}
.wa-op-item {
    padding: 10px 14px;
    border-bottom: 1px solid #f3f4f6;
    cursor: default;
    transition: background 0.15s;
}
.wa-op-item:hover { background: #f9fafb; }
.wa-op-num { font-weight: 700; font-size: 13px; color: #111827; }
.wa-op-date { font-size: 11px; color: #9ca3af; margin-left: 6px; }
.wa-op-total { font-weight: 600; font-size: 13px; color: #111827; }
.wa-op-status {
    display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: capitalize;
}
.wa-op-status.processing { background: #FEF3C7; color: #92400E; }
.wa-op-status.out_for_delivery, .wa-op-status.shipped { background: #DBEAFE; color: #1E40AF; }
.wa-op-status.delivered { background: #D1FAE5; color: #065F46; }
.wa-op-status.cancelled, .wa-op-status.refunded { background: #FEE2E2; color: #991B1B; }
.wa-op-status.pending { background: #F3F4F6; color: #6B7280; }
.wa-op-rider { font-size: 11px; color: #6b7280; margin-top: 2px; }
.wa-op-items { font-size: 11px; color: #9ca3af; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wa-op-inv-btn {
    margin-top: 6px; padding: 4px 10px; background: #FFF7ED; border: 1px solid #FED7AA; border-radius: 6px;
    cursor: pointer; font-size: 11px; font-weight: 500; color: #9a3412; transition: all 0.15s;
}
.wa-op-inv-btn:hover { background: #FFEDD5; border-color: #F59E0B; }
.wa-op-toggle {
    position: absolute; right: 0; top: 50%; transform: translateY(-50%);
    background: #fff; border: 1px solid #e5e7eb; border-right: none; border-radius: 6px 0 0 6px;
    padding: 8px 4px; cursor: pointer; font-size: 12px; z-index: 5; box-shadow: -2px 0 4px rgba(0,0,0,0.06);
    display: none;
}
.wa-op-toggle:hover { background: #f0fdf4; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Empty State ── */
.wa-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #f0f2f5;
    padding: 40px;
}
.wa-empty-icon {
    width: 200px;
    height: 200px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.wa-empty-phone {
    width: 100px;
    height: 150px;
    background: #fff;
    border-radius: 16px;
    border: 2.5px solid #d1d5db;
    position: relative;
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
    overflow: hidden;
    z-index: 2;
}
.wa-empty-phone-top {
    height: 26px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    padding: 0 8px;
    gap: 4px;
}
.wa-empty-phone-top .dot { width: 14px; height: 14px; border-radius: 50%; background: rgba(255,255,255,0.35); }
.wa-empty-phone-top .bar { flex: 1; height: 5px; background: rgba(255,255,255,0.4); border-radius: 3px; }
.wa-empty-phone-body { padding: 6px; display: flex; flex-direction: column; gap: 5px; }
.wa-empty-bbl {
    border-radius: 6px;
    height: 8px;
}
.wa-empty-bbl.in { background: #fff; border: 1px solid #e5e7eb; align-self: flex-start; width: 55px; border-bottom-left-radius: 2px; }
.wa-empty-bbl.out { background: #d9fdd3; align-self: flex-end; width: 45px; border-bottom-right-radius: 2px; }
.wa-empty-bbl.in.lg { width: 65px; }
.wa-empty-ring {
    position: absolute;
    border-radius: 50%;
    border: 2px solid rgba(22,163,74,0.15);
    animation: ring-pulse 3s ease-in-out infinite;
}
.wa-empty-ring:nth-child(1) { width: 150px; height: 150px; }
.wa-empty-ring:nth-child(2) { width: 190px; height: 190px; animation-delay: 0.5s; }
@keyframes ring-pulse {
    0%, 100% { opacity: 0.3; transform: scale(0.95); }
    50% { opacity: 0.7; transform: scale(1.05); }
}
.wa-empty h3 { font-size: 22px; color: #374151; margin: 0 0 6px; font-weight: 700; }
.wa-empty p { font-size: 14px; margin: 0; color: #9ca3af; }

/* ── Load More ── */
.wa-load-more { text-align: center; padding: 10px; }
.wa-load-more a { font-size: 13px; color: #16a34a; font-weight: 500; cursor: pointer; text-decoration: none; }
.wa-load-more a:hover { text-decoration: underline; }

/* ── Loading ── */
.wa-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px;
    color: #9ca3af;
    font-size: 14px;
    gap: 8px;
}

/* ── Modal Overlay ── */
.wa-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.45);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(3px);
    animation: mFadeIn 0.15s ease-out;
}
@keyframes mFadeIn { from { opacity: 0; } to { opacity: 1; } }
.wa-modal {
    background: #fff;
    border-radius: 16px;
    width: 520px;
    max-width: 92vw;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 60px -12px rgba(0,0,0,0.3);
    animation: mSlideIn 0.2s ease-out;
}
@keyframes mSlideIn {
    from { transform: translateY(20px) scale(0.97); opacity: 0; }
    to { transform: translateY(0) scale(1); opacity: 1; }
}
.wa-modal-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    flex-shrink: 0;
}
.wa-modal-hdr h3 { font-size: 17px; font-weight: 700; margin: 0; color: #111827; }
.wa-modal-x {
    font-size: 18px;
    color: #9ca3af;
    cursor: pointer;
    background: #f3f4f6;
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.wa-modal-x:hover { background: #e5e7eb; color: #374151; }
.wa-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db transparent;
}
.wa-modal-body::-webkit-scrollbar { width: 5px; }
.wa-modal-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }

/* ── Template Cards ── */
.wa-tpl-card {
    background: #fff;
    border: 1.5px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.15s;
}
.wa-tpl-card:hover { border-color: #86efac; box-shadow: 0 2px 12px rgba(22,163,74,0.08); }
.wa-tpl-name {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.wa-tpl-name::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #16a34a;
    flex-shrink: 0;
}
.wa-tpl-body {
    font-size: 13px;
    color: #374151;
    line-height: 1.5;
    white-space: pre-wrap;
    background: #f8fafc;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 10px;
}
.wa-tpl-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.wa-tpl-btn-tag {
    background: #f0fdf4;
    padding: 5px 14px;
    border-radius: 8px;
    font-size: 12px;
    color: #16a34a;
    font-weight: 600;
    border: 1px solid #bbf7d0;
}
.wa-tpl-params { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
.wa-tpl-param-in {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid #d1d5db;
    border-radius: 10px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
}
.wa-tpl-param-in:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.08); }
.wa-tpl-send {
    width: 100%;
    padding: 10px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
}
.wa-tpl-send:hover { background: #15803d; }

/* ── Template Manager ── */
.wa-mgr-info {
    margin-bottom: 16px;
    padding: 12px 14px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    font-size: 13px;
    color: #166534;
    line-height: 1.4;
}
.wa-mgr-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 8px;
}
.wa-mgr-item:hover { border-color: #d1d5db; }
.wa-mgr-item-name { font-weight: 600; font-size: 14px; color: #111827; }
.wa-mgr-item-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
.wa-mgr-del {
    padding: 4px 10px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    color: #dc2626;
    font-size: 12px;
    cursor: pointer;
    flex-shrink: 0;
}
.wa-mgr-del:hover { background: #fee2e2; }
.wa-mgr-divider {
    border-top: 1px solid #e5e7eb;
    padding-top: 16px;
    margin-top: 16px;
}
.wa-mgr-divider h4 { font-size: 15px; font-weight: 700; margin: 0 0 12px; color: #111827; }
.wa-mgr-grid { display: grid; gap: 10px; margin-bottom: 10px; }
.wa-mgr-grid.cols-2 { grid-template-columns: 1fr 1fr; }
.wa-mgr-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.wa-mgr-label { font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px; }
.wa-mgr-input {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
    font-family: inherit;
}
.wa-mgr-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.08); }
textarea.wa-mgr-input { resize: vertical; }
select.wa-mgr-input { background: #fff; cursor: pointer; }
.wa-mgr-save {
    width: 100%;
    padding: 11px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 4px;
}
.wa-mgr-save:hover { background: #15803d; }

/* Template-scope radio: highlight the selected segment */
.tpl-scope-opt { transition: background .12s, border-color .12s, box-shadow .12s; }
.tpl-scope-opt:hover { border-color: #cbd5e1; }
.tpl-scope-opt.tpl-scope-selected[data-scope="common"]  { border-color: #94a3b8; background: #f1f5f9; box-shadow: 0 0 0 1px #94a3b8 inset; }
.tpl-scope-opt.tpl-scope-selected[data-scope="regular"] { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 1px #2563eb inset; }
.tpl-scope-opt.tpl-scope-selected[data-scope="qurbani"] { border-color: #d97706; background: #fffbeb; box-shadow: 0 0 0 1px #d97706 inset; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .wa-sidebar { width: 100%; min-width: auto; max-width: none; }
    .wa-chat {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 50;
    }
    .wa-chat-back { display: flex; }
    .wa-empty { display: none !important; }
    .wa-chat-msgs { padding: 16px; }
    .wa-chat-foot { padding: 10px 12px; }
    .wa-msg { max-width: 80%; }
    .wa-orders-panel, #waOrdersToggle { display: none !important; }
}
@media (min-width: 769px) {
    .wa-chat-back { display: none; }
}
</style>
@endpush

@section('content')
<div class="wa-page" id="waApp">

    <!-- ═══ LEFT: Sidebar ═══ -->
    <div class="wa-sidebar" id="waSidebar">
        <div class="wa-side-hdr">
            <div class="wa-side-hdr-top">
                <h2>Messages</h2>
                <div class="wa-side-hdr-actions">
                    <button class="wa-btn wa-btn-green" id="waNewMsgBtn" onclick="toggleNewMessage()">+ New</button>
                    <button class="wa-btn wa-btn-gray" onclick="openTemplateManager()" title="Manage Templates">⚙</button>
                </div>
            </div>
            <div class="wa-search-wrap">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.868-3.834zm-5.44.856a4.8 4.8 0 1 1 0-9.6 4.8 4.8 0 0 1 0 9.6z"/></svg>
                <input type="text" class="wa-search" id="waSearch" placeholder="Search name, phone or city..." />
            </div>
            {{-- Search-mode toggle. Defaults to "Names" for backward-compat
                 with how the search has always worked; "Chats" searches the
                 actual message content (see WhatsAppWebController@getConversations
                 with search_mode=chats). Kept tiny so it doesn't steal space
                 from the filter row below. --}}
            <div class="wa-search-mode" role="tablist" aria-label="Search mode" style="display:flex;gap:4px;margin-top:6px;">
                <button type="button" class="wa-searchmode-btn active" data-mode="customers" role="tab" aria-selected="true">👤 Names</button>
                <button type="button" class="wa-searchmode-btn" data-mode="chats" role="tab" aria-selected="false" title="Search inside message contents">💬 Chats</button>
            </div>
            <div class="wa-filters">
                <button class="wa-filter-btn active" data-filter="all">All</button>
                <button class="wa-filter-btn" data-filter="unread">Unread</button>
                <button class="wa-filter-btn" data-filter="qurbani" id="waFilterQurbani" style="display:none;" title="Conversations auto-flagged as Qurbani">🐐 Qurbani</button>
                <!-- Label filter: picks one label id to narrow the inbox. Uses a
                     dropdown instead of one pill per label so the strip doesn't
                     explode if the workspace has many labels. -->
                <div class="wa-label-filter-wrap">
                    <button class="wa-label-filter-btn" id="waLabelFilterBtn" onclick="toggleLabelFilter()" title="Filter by label">🏷️ Label</button>
                    <div class="wa-label-filter-menu" id="waLabelFilterMenu"></div>
                </div>
                {{-- Phase 2 — @me: only conversations where the current user
                     has an unread mention. Tiny red dot appears when there
                     are any unread mentions regardless of toggle state. --}}
                <button class="wa-filter-btn wa-filter-me" id="waFilterMe" onclick="toggleAssignedToMe()" title="Conversations you were tagged in">
                    <span>@ me</span>
                    <span class="wa-mention-pip" id="waMentionPip" style="display:none;"></span>
                </button>
                @if(!(($waIsLimited ?? false)))
                <button class="wa-btn wa-btn-gray" onclick="openQurbaniSettings()" title="Qurbani tab settings" style="margin-left:auto;padding:3px 10px;font-size:12px;">⚙</button>
                @endif
            </div>
            @if(($waIsLimited ?? false))
            <div style="margin-top:8px;padding:7px 10px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:6px;line-height:1.35;" title="Your account has Limited Messages access. Older conversations are hidden.">
                <span>⚠</span>
                <span><strong>Limited view</strong> — showing messages from today and yesterday only.</span>
            </div>
            @endif
        </div>

        <!-- New message panel -->
        <div id="waNewMsgPanel" class="wa-new-panel">
            <div class="wa-new-panel-hdr">
                <span>New Message — Search Customer</span>
                <button class="wa-new-panel-close" onclick="toggleNewMessage()">&times;</button>
            </div>
            <input type="text" id="waCustomerSearch" class="wa-new-input" placeholder="Search customer name or phone..." />
            <div id="waCustomerResults" class="wa-cust-results"></div>
        </div>

        <div class="wa-conv-list" id="waConvList">
            <div class="wa-loading">Loading conversations...</div>
        </div>
    </div>

    <!-- ═══ RIGHT: Chat Panel ═══ -->
    <div class="wa-chat" id="waChat">
        <div class="wa-chat-hdr" id="waChatHeader">
            <button class="wa-chat-back" id="waChatBack" title="Back">←</button>
            <div class="wa-chat-avatar" id="waChatAvatar"></div>
            <div class="wa-chat-hdr-info">
                <h3 id="waChatName"></h3>
                <p id="waChatSub"></p>
            </div>
            <div class="wa-session-badge" id="waSessionBadge" style="display:none;">24h expired</div>
            <button id="waOrdersToggle" onclick="toggleOrdersPanel()" style="background:rgba(255,255,255,0.2);border:none;border-radius:6px;padding:6px 10px;cursor:pointer;color:#fff;font-size:12px;font-weight:500;display:none;margin-left:8px;white-space:nowrap;" title="Customer Orders">📋 Orders</button>
            <div class="wa-chat-menu-wrap">
                <button class="wa-chat-menu-btn" id="waChatMenuBtn" title="More actions" onclick="toggleChatMenu()">⋮</button>
                <div class="wa-chat-menu" id="waChatMenu">
                    <button onclick="doMarkUnread()" title="Mark this conversation as unread">
                        <span>📩</span> Mark as Unread
                    </button>
                    <button onclick="openLabelsModal()" title="Apply or manage labels">
                        <span>🏷️</span> Labels…
                    </button>
                </div>
            </div>
        </div>
        <!-- Labels row under the header — shows labels applied to the active
             conversation. Hidden when empty via CSS. -->
        <div class="wa-chat-hdr-labels" id="waChatHdrLabels"></div>
        <div class="wa-chat-msgs" id="waChatMessages">
            <div class="wa-loading">Loading messages...</div>
        </div>
        <div class="wa-chat-foot" id="waChatInput">
            <div id="waSessionExpiredBar" class="wa-expired-bar" style="display:none;">
                <p>The 24-hour messaging window has expired.</p>
                <div style="display:flex;gap:8px;">
                    <button onclick="openTemplatePicker()">Send Template Message</button>
                    <button onclick="openInvoicePicker()" style="background:#f59e0b;color:#fff;">📄 Send Invoice</button>
                </div>
            </div>
            <div id="waActiveSessionInput">
                <div id="waSessionTimer" class="wa-session-timer" style="display:none;"></div>
                <div class="wa-input-row">
                    <textarea class="wa-text-input" id="waMessageInput" rows="1" placeholder="Type a message..." maxlength="4096"></textarea>
                    <button class="wa-send-btn" id="waSendBtn" title="Send">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/></svg>
                    </button>
                </div>
                <div class="wa-tpl-link" style="display:flex;gap:12px;">
                    <a onclick="openTemplatePicker()">📋 Templates</a>
                    <a onclick="openInvoicePicker()" style="color:#d97706;cursor:pointer;">📄 Send Invoice</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ ORDERS PANEL (collapsible right sidebar) ═══ -->
    <div class="wa-orders-panel" id="waOrdersPanel">
        <div class="wa-op-hdr">
            <h4>📋 Customer Orders</h4>
            <button class="wa-op-close" onclick="toggleOrdersPanel()" title="Close">&times;</button>
        </div>
        <div class="wa-op-list" id="waOrdersList">
            <div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">Select a conversation to see orders</div>
        </div>
    </div>

    <!-- ═══ RIGHT: Empty State ═══ -->
    <div class="wa-empty" id="waEmptyState">
        <div class="wa-empty-icon">
            <div class="wa-empty-ring"></div>
            <div class="wa-empty-ring"></div>
            <div class="wa-empty-phone">
                <div class="wa-empty-phone-top">
                    <span class="dot"></span>
                    <span class="bar"></span>
                </div>
                <div class="wa-empty-phone-body">
                    <div class="wa-empty-bbl in lg"></div>
                    <div class="wa-empty-bbl out"></div>
                    <div class="wa-empty-bbl in"></div>
                    <div class="wa-empty-bbl out"></div>
                </div>
            </div>
        </div>
        <h3>Start a conversation</h3>
        <p>Select a customer from the left or tap <strong>+ New</strong> to begin</p>
    </div>
</div>

<!-- ═══ MODAL: Qurbani Tab Settings ═══ -->
<div class="wa-modal-overlay" id="waQurbaniSettingsModal" style="display:none;" onclick="if(event.target===this)closeQurbaniSettings()">
    <div class="wa-modal" style="width:560px;max-width:94vw;">
        <div class="wa-modal-hdr">
            <h3>🐐 Qurbani Tab Settings</h3>
            <button class="wa-modal-x" onclick="closeQurbaniSettings()">✕</button>
        </div>
        <div class="wa-modal-body" style="padding:18px;">
            <p style="font-size:13px;color:#6b7280;margin-top:0;">
                Conversations are auto-tagged as Qurbani when the customer has a Qurbani
                order for the active year, a Qurbani-only template was sent to them, or
                any of the last few inbound messages contains a keyword below.
            </p>

            <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;margin-bottom:14px;cursor:pointer;">
                <input type="checkbox" id="qcfgEnabled" style="transform:scale(1.2);" />
                <span style="font-weight:600;color:#166534;">Enable Qurbani tab &amp; goat badge</span>
            </label>

            <div id="qcfgFields">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Keywords (comma-separated)</label>
                    <textarea id="qcfgKeywords" rows="3" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;" placeholder="qurbani, eid, bakra, goat, hissa, ..."></textarea>
                    <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Case-insensitive, matched anywhere in the message body. "beef" is deliberately excluded because it overlaps with regular meat orders.</div>
                </div>

                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Messages to scan</label>
                        <input type="number" id="qcfgLookback" min="1" max="20" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px;">How many recent inbound messages to check per customer (1–20).</div>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Active Qurbani year</label>
                        <input type="number" id="qcfgYear" min="2000" max="2100" placeholder="{{ date('Y') }}" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Leave blank to use the current year.</div>
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #e5e7eb;">
                <button onclick="rescanQurbani()" id="qcfgRescanBtn" class="wa-btn wa-btn-gray" style="padding:8px 14px;" title="Re-classify all existing conversations using the current settings">🔄 Rescan all conversations</button>
                <div style="display:flex;gap:8px;">
                    <button onclick="closeQurbaniSettings()" class="wa-btn wa-btn-gray" style="padding:8px 16px;">Cancel</button>
                    <button onclick="saveQurbaniSettings()" id="qcfgSaveBtn" class="wa-btn wa-btn-green" style="padding:8px 16px;">Save</button>
                </div>
            </div>

            <div id="qcfgStatus" style="margin-top:10px;font-size:12px;color:#6b7280;min-height:16px;"></div>
        </div>
    </div>
</div>

<!-- ═══ MODAL: Template Picker ═══ -->
<div class="wa-modal-overlay" id="waTemplateModal" style="display:none;" onclick="if(event.target===this)closeTemplatePicker()">
    <div class="wa-modal">
        <div class="wa-modal-hdr">
            <h3>Send Template</h3>
            <button class="wa-modal-x" onclick="closeTemplatePicker()">✕</button>
        </div>
        <div class="wa-modal-body" id="waTemplateList">
            <div class="wa-loading">Loading templates...</div>
        </div>
    </div>
</div>

<!-- ═══ MODAL: Template Manager ═══ -->
<div class="wa-modal-overlay" id="waTemplateManager" style="display:none;" onclick="if(event.target===this)closeTemplateManager()">
    <div class="wa-modal" style="width:640px;">
        <div class="wa-modal-hdr">
            <h3>Manage Templates</h3>
            <button class="wa-modal-x" onclick="closeTemplateManager()">✕</button>
        </div>
        <div class="wa-modal-body" id="waTemplateManagerBody">
            <div class="wa-mgr-info">
                Add your WhatsApp Business approved templates here. These will appear as options when sending messages.
            </div>
            <div id="waExistingTemplates"></div>
            <div class="wa-mgr-divider">
                <div id="tplFormBanner" style="display:none;margin-bottom:10px;padding:8px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;">
                    You are editing an existing template. Click <b>Cancel</b> to discard changes.
                </div>
                <h4 id="tplFormHeading">Add New Template</h4>
                <div class="wa-mgr-grid cols-2">
                    <div>
                        <label class="wa-mgr-label">Template Name (exact match from WhatsApp)</label>
                        <input id="tplName" class="wa-mgr-input" placeholder="e.g. capacity_full" />
                    </div>
                    <div>
                        <label class="wa-mgr-label">Display Name</label>
                        <input id="tplDisplayName" class="wa-mgr-input" placeholder="e.g. Capacity Full - Tomorrow" />
                    </div>
                </div>
                <div style="margin-bottom:10px;">
                    <label class="wa-mgr-label">Body Text (use {{1}}, {{2}} for variables)</label>
                    <textarea id="tplBody" rows="3" class="wa-mgr-input" placeholder="Dear {{1}}, your order {{2}} is ready..."></textarea>
                </div>
                <div class="wa-mgr-grid cols-3">
                    <div>
                        <label class="wa-mgr-label">Category</label>
                        <select id="tplCategory" class="wa-mgr-input">
                            <option value="utility">Utility</option>
                            <option value="marketing">Marketing</option>
                        </select>
                    </div>
                    <div>
                        <label class="wa-mgr-label">Variable Count</label>
                        <input id="tplVarCount" type="number" min="0" max="10" value="0" class="wa-mgr-input" />
                    </div>
                    <div>
                        <label class="wa-mgr-label">Has Buttons?</label>
                        <select id="tplHasButtons" class="wa-mgr-input">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
                <div id="tplButtonLabelsDiv" style="display:none;margin-bottom:10px;">
                    <label class="wa-mgr-label">Button Labels (comma separated)</label>
                    <input id="tplButtonLabels" class="wa-mgr-input" placeholder="Yes, deliver tomorrow, Cancel order" />
                </div>
                <div class="wa-mgr-grid cols-2" style="margin-bottom:12px;">
                    <div>
                        <label class="wa-mgr-label">Header Text (optional)</label>
                        <input id="tplHeader" class="wa-mgr-input" placeholder="e.g. Order Update" />
                    </div>
                    <div>
                        <label class="wa-mgr-label">Footer Text (optional)</label>
                        <input id="tplFooter" class="wa-mgr-input" placeholder="e.g. Nizami Farms" />
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="wa-mgr-label">Show In (where this template appears)</label>
                    <div style="display:flex;gap:16px;margin-top:4px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowMessages" checked /> Messages</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowOrders" checked /> Open Orders</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowCustomers" checked /> Customers</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowShopify" checked /> Shopify</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;background:#fffbeb;padding:2px 6px;border-radius:4px;border:1px solid #fde68a;margin-top:4px;"><input type="checkbox" id="tplShowInvoice" /> 📄 Regular Invoice</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;background:#fef3c7;padding:2px 6px;border-radius:4px;border:1px solid #f59e0b;margin-top:4px;"><input type="checkbox" id="tplShowQurbaniInvoice" /> 🐄 Qurbani Invoice</label>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:8px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                        <input type="checkbox" id="tplIsActive" checked />
                        <div>
                            <div style="font-weight:600;color:#15803d;">✓ Active</div>
                            <div style="font-size:11px;color:#64748b;">Uncheck to hide this template everywhere without deleting it</div>
                        </div>
                    </label>
                </div>
                <div style="margin-bottom:12px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                    <div style="font-weight:600;color:#334155;font-size:13px;margin-bottom:6px;">👥 Who can see this template?</div>
                    <div style="font-size:11px;color:#64748b;margin-bottom:8px;">Pick where this template shows up. Marketing / broadcast messages should stay "Common".</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;" id="tplScopeGroup">
                        <label class="tpl-scope-opt" data-scope="common" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:8px;background:#fff;border:2px solid #e5e7eb;border-radius:6px;">
                            <input type="radio" name="tplScope" value="common" id="tplScopeCommon" checked style="margin:0;" />
                            <div>
                                <div style="font-weight:600;color:#334155;">🌐 Common</div>
                                <div style="font-size:10px;color:#64748b;">Both Regular &amp; Qurbani</div>
                            </div>
                        </label>
                        <label class="tpl-scope-opt" data-scope="regular" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:8px;background:#fff;border:2px solid #e5e7eb;border-radius:6px;">
                            <input type="radio" name="tplScope" value="regular" id="tplScopeRegular" style="margin:0;" />
                            <div>
                                <div style="font-weight:600;color:#1e40af;">🛒 Regular only</div>
                                <div style="font-size:10px;color:#64748b;">Hidden on Qurbani pages</div>
                            </div>
                        </label>
                        <label class="tpl-scope-opt" data-scope="qurbani" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:8px;background:#fff;border:2px solid #e5e7eb;border-radius:6px;">
                            <input type="radio" name="tplScope" value="qurbani" id="tplScopeQurbani" style="margin:0;" />
                            <div>
                                <div style="font-weight:600;color:#b45309;">🐄 Qurbani only</div>
                                <div style="font-size:10px;color:#64748b;">Hidden on Regular pages</div>
                            </div>
                        </label>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplIsDefault" /> ⭐ Set as default invoice template</label>
                    <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Default template auto-selects when sending invoices</div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button id="tplSaveBtn" onclick="saveTemplateForm()" class="wa-mgr-save" style="flex:1;">Save Template</button>
                    <button id="tplCancelBtn" onclick="cancelTemplateEdit()" style="display:none;padding:11px 20px;background:#e5e7eb;color:#374151;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ LABELS MODAL ═══
     Two sections:
       • Apply/remove labels for the currently-open conversation (toggles).
       • Manage label library (create/edit/delete) — only visible to users
         with manage_whatsapp_labels. System labels can be edited but the
         delete button shows a confirmation warning.
-->
<div class="wa-labels-modal" id="waLabelsModal" onclick="if(event.target===this)closeLabelsModal()">
    <div class="wa-labels-box" onclick="event.stopPropagation()">
        <div class="wa-labels-hdr">
            <h3>Conversation Labels</h3>
            <button class="wa-new-panel-close" onclick="closeLabelsModal()">&times;</button>
        </div>
        <div class="wa-labels-body">
            <div class="wa-labels-section-title">Apply labels</div>
            <div id="waLabelToggles" style="margin-bottom:10px;">
                <div style="font-size:12px;color:#9ca3af;">Loading…</div>
            </div>

            <div id="waLabelManageBlock" style="display:none;">
                <div class="wa-labels-section-title" style="margin-top:14px;">Manage library</div>
                <div id="waLabelManageList"></div>
                <div class="wa-label-create-row">
                    <input type="text" id="waLabelNewName" placeholder="New label name (e.g. Complaint)" maxlength="60">
                    <input type="color" id="waLabelNewColor" value="#16A34A" title="Label colour">
                    {{-- Phase 2: optional user-mention binding. When a user
                         is picked, applying the label pushes them an FCM
                         notification and tracks the mention as unread for
                         them until they open the conversation. --}}
                    <select id="waLabelNewUser" class="wa-label-user-select" title="Mention a user (optional)">
                        <option value="">No user (generic)</option>
                    </select>
                    <button onclick="createLabel()">Add</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('demo1_js')
@verbatim
<script>
(function() {
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const POLL_INTERVAL = 10000;
    let activeConvId = null;
    let activeConv = null;
    let currentFilter = 'all';
    // Phase 2 — when true, ?assigned_to_me=1 is added to the inbox query
    // and only conversations with unread @mentions for the current user
    // are returned. Toggled by the @me filter button.
    let assignedToMe = false;
    // 'customers' (legacy behaviour, searches name/phone/city) or 'chats'
    // (searches inside message content via the backend's search_mode param).
    // Toggled by the small pill row underneath the search input.
    let currentSearchMode = 'customers';
    let searchTimeout = null;
    let convPollTimer = null;
    let msgPollTimer = null;
    let templates = [];
    var _cachedApiTemplates = null;

    // Qurbani tab / badge master switch. Refreshed from the backend on init
    // and after the settings drawer is saved.
    let waQurbaniEnabled = true;
    let waQurbaniSettings = null;

    function loadQurbaniSettings() {
        return apiFetch('/messages/qurbani-settings').then(d => {
            if (d.success && d.settings) {
                waQurbaniSettings = d.settings;
                waQurbaniEnabled = !!d.settings.enabled;
                const tabBtn = document.getElementById('waFilterQurbani');
                if (tabBtn) tabBtn.style.display = waQurbaniEnabled ? '' : 'none';
                if (!waQurbaniEnabled && currentFilter === 'qurbani') {
                    currentFilter = 'all';
                    document.querySelectorAll('.wa-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.filter === 'all'));
                    loadConversations();
                }
            }
        }).catch(() => {});
    }

    window.openQurbaniSettings = function() {
        document.getElementById('waQurbaniSettingsModal').style.display = 'flex';
        document.getElementById('qcfgStatus').textContent = '';
        // Hydrate from whatever is already cached; refresh in the background.
        if (waQurbaniSettings) hydrateQurbaniForm(waQurbaniSettings);
        loadQurbaniSettings().then(() => { if (waQurbaniSettings) hydrateQurbaniForm(waQurbaniSettings); });
    };
    window.closeQurbaniSettings = function() {
        document.getElementById('waQurbaniSettingsModal').style.display = 'none';
    };

    function hydrateQurbaniForm(s) {
        document.getElementById('qcfgEnabled').checked = !!s.enabled;
        document.getElementById('qcfgKeywords').value = Array.isArray(s.keywords) ? s.keywords.join(', ') : (s.keywords || '');
        document.getElementById('qcfgLookback').value = s.lookback || 5;
        document.getElementById('qcfgYear').value = s.year || '';
    }

    window.saveQurbaniSettings = function() {
        const btn = document.getElementById('qcfgSaveBtn');
        const status = document.getElementById('qcfgStatus');
        btn.disabled = true; status.textContent = 'Saving...';
        const payload = {
            enabled: document.getElementById('qcfgEnabled').checked,
            keywords: document.getElementById('qcfgKeywords').value,
            lookback: parseInt(document.getElementById('qcfgLookback').value, 10) || 5,
            year: document.getElementById('qcfgYear').value || null,
        };
        apiFetch('/messages/qurbani-settings', { method: 'POST', body: JSON.stringify(payload) })
            .then(d => {
                btn.disabled = false;
                if (d.success) {
                    waQurbaniSettings = d.settings;
                    waQurbaniEnabled = !!d.settings.enabled;
                    const tabBtn = document.getElementById('waFilterQurbani');
                    if (tabBtn) tabBtn.style.display = waQurbaniEnabled ? '' : 'none';
                    status.style.color = '#16a34a';
                    status.textContent = 'Saved. Settings apply to new messages automatically. Use "Rescan" below to re-evaluate existing conversations.';
                    loadConversations();
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = d.message || 'Failed to save settings.';
                }
            })
            .catch(e => { btn.disabled = false; status.style.color = '#ef4444'; status.textContent = 'Failed to save settings.'; });
    };

    window.rescanQurbani = function() {
        if (!confirm('Re-evaluate every conversation with the current keyword / lookback / year settings? This may take a few seconds.')) return;
        const btn = document.getElementById('qcfgRescanBtn');
        const status = document.getElementById('qcfgStatus');
        btn.disabled = true; status.style.color = '#6b7280'; status.textContent = 'Rescanning...';
        apiFetch('/messages/qurbani-rescan', { method: 'POST' })
            .then(d => {
                btn.disabled = false;
                if (d.success) {
                    status.style.color = '#16a34a';
                    status.textContent = `Done. Scanned ${d.total} · newly flagged ${d.flagged} · cleared ${d.cleared}.`;
                    loadConversations();
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = d.message || 'Rescan failed.';
                }
            })
            .catch(() => { btn.disabled = false; status.style.color = '#ef4444'; status.textContent = 'Rescan failed.'; });
    };

    function apiFetch(url, opts = {}) {
        opts.headers = { ...(opts.headers || {}), 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' };
        return fetch(url, opts).then(r => r.json());
    }

    function fmtTime(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const diffDays = Math.round((today - msgDay) / 86400000);
        if (diffDays === 0) return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return d.toLocaleDateString([], {weekday:'short'});
        return d.toLocaleDateString([], {day:'numeric',month:'short'});
    }
    function fmtMsgTime(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const diffDays = Math.round((today - msgDay) / 86400000);
        const time = d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        if (diffDays === 0) return time;
        if (diffDays === 1) return 'Yesterday ' + time;
        return d.toLocaleDateString([], {day:'numeric',month:'short'}) + ' ' + time;
    }
    function fmtDateLabel(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const diffDays = Math.round((today - msgDay) / 86400000);
        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return d.toLocaleDateString([], {weekday:'long'});
        return d.toLocaleDateString([], {weekday:'short', day:'numeric', month:'short', year:'numeric'});
    }
    function statusIcon(s) {
        return {sent:'✓',delivered:'✓✓',read:'✓✓',failed:'✕'}[s] || '';
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    let sessionTimerInterval = null;
    function updateSessionUI(conv) {
        const sessionActive = conv.session_active;
        document.getElementById('waSessionBadge').style.display = sessionActive ? 'none' : 'block';
        document.getElementById('waSessionExpiredBar').style.display = sessionActive ? 'none' : 'block';
        document.getElementById('waActiveSessionInput').style.display = sessionActive ? 'block' : 'none';

        if (sessionTimerInterval) { clearInterval(sessionTimerInterval); sessionTimerInterval = null; }
        const timerEl = document.getElementById('waSessionTimer');
        if (!conv.session_expires_at || !sessionActive) {
            timerEl.style.display = 'none';
            return;
        }
        function tick() {
            const remaining = new Date(conv.session_expires_at) - new Date();
            if (remaining <= 0) {
                timerEl.style.display = 'none';
                document.getElementById('waSessionBadge').style.display = 'block';
                document.getElementById('waSessionExpiredBar').style.display = 'block';
                document.getElementById('waActiveSessionInput').style.display = 'none';
                if (sessionTimerInterval) { clearInterval(sessionTimerInterval); sessionTimerInterval = null; }
                return;
            }
            const h = Math.floor(remaining / 3600000);
            const m = Math.floor((remaining % 3600000) / 60000);
            timerEl.style.display = 'block';
            timerEl.className = 'wa-session-timer' + (h === 0 && m < 30 ? ' expiring' : '');
            timerEl.textContent = h > 0
                ? `Free messaging window closes in ${h}h ${m}m`
                : `Free messaging window closes in ${m}m`;
        }
        tick();
        sessionTimerInterval = setInterval(tick, 30000);
    }

    // ── Conversations ──
    function loadConversations() {
        const search = document.getElementById('waSearch').value.trim();
        let url = '/messages/conversations?filter=' + currentFilter;
        if (search) {
            url += '&search=' + encodeURIComponent(search);
            // Only meaningful when there's an actual search term; saves us
            // sending the mode on every no-search refresh.
            url += '&search_mode=' + encodeURIComponent(currentSearchMode);
        }
        apiFetch(url).then(d => {
            if (!d.success) return;
            renderConversations(d.conversations, { searchMode: currentSearchMode, searchTerm: search });
        });
    }

    // Phase 2 — poll the mentions count every 30s and keep the red pip
    // on the @me filter button in sync even when the user isn't actively
    // filtering. Only runs when the user has any chance of seeing a
    // mention (i.e. they have WA view perm — we can approximate by just
    // trying and ignoring 403s).
    function refreshMentionsPip() {
        apiFetch('/messages/mentions-count').then(d => {
            if (!d || !d.success) return;
            const pip = document.getElementById('waMentionPip');
            if (!pip) return;
            if ((d.mentions_count || 0) > 0) {
                pip.style.display = 'inline-block';
            } else {
                pip.style.display = 'none';
            }
        }).catch(() => {});
    }
    window.toggleAssignedToMe = function() {
        assignedToMe = !assignedToMe;
        const btn = document.getElementById('waFilterMe');
        if (btn) btn.classList.toggle('active', assignedToMe);
        loadConversations();
    };

    function renderConversations(convs, opts = {}) {
        const el = document.getElementById('waConvList');
        const inChatSearch = opts.searchMode === 'chats' && (opts.searchTerm || '').length > 0;
        if (!convs.length) {
            el.innerHTML = inChatSearch
                ? '<div class="wa-loading">No messages match “' + esc(opts.searchTerm) + '”.<br><span style="font-size:12px;color:#9ca3af;">Try a shorter word or switch back to Names.</span></div>'
                : '<div class="wa-loading">No conversations found</div>';
            return;
        }
        el.innerHTML = convs.map(c => {
            const isActive = c.id === activeConvId;
            const isUnread = c.unread_count > 0;
            let cls = 'wa-conv-item';
            if (isActive) cls += ' active';
            if (isUnread) cls += ' unread';
            // Only show the goat badge when the Qurbani feature is enabled
            // (master switch in settings drawer); we hide the badge otherwise.
            const qBadge = (waQurbaniEnabled && c.is_qurbani) ? '<span title="Qurbani conversation" style="margin-right:4px;font-size:14px;">🐐</span>' : '';
            // Chat-search mode: append a snippet of the matched message
            // under the last-message preview, with the query highlighted.
            // We still show the last-message line above so operators keep
            // their usual context of "where is this conversation at now".
            const matchBlock = (inChatSearch && c.match_snippet)
                ? `<div class="wa-conv-match" title="Matched message content">
                        ${c.match_direction === 'outbound' ? '✓ ' : ''}${highlightMatch(c.match_snippet, opts.searchTerm)}${c.match_count > 1 ? ` <span style="color:#6366f1;font-weight:600;">+${c.match_count - 1} more</span>` : ''}
                   </div>`
                : '';
            return `<div class="${cls}" onclick="openConv(${c.id})" data-id="${c.id}">
                <div class="wa-avatar">${(c.customer_name||'?')[0].toUpperCase()}</div>
                <div class="wa-conv-info">
                    <div class="wa-conv-top">
                        <div class="wa-conv-name">${qBadge}${esc(c.customer_name || c.wa_phone)}</div>
                        <div class="wa-conv-time">${fmtTime(c.last_message_at)}</div>
                    </div>
                    <div class="wa-conv-bottom">
                        <div class="wa-conv-preview">${c.last_message_direction==='outbound'?'✓ ':''}${esc(c.last_message_preview||'No messages yet')}</div>
                        ${isUnread ? `<div class="wa-unread-badge">${c.unread_count}</div>` : ''}
                    </div>
                    ${matchBlock}
                    ${c.customer_city ? `<div class="wa-conv-city">${esc(c.customer_city)}</div>` : ''}
                </div>
            </div>`;
        }).join('');
    }

    // Escape-and-highlight helper for chat-search snippets. Escapes the
    // whole snippet first, then wraps case-insensitive occurrences of the
    // search term in <mark>. Operates on text (not HTML) so XSS is safe.
    function highlightMatch(snippet, term) {
        const safe = esc(snippet);
        if (!term) return safe;
        const pattern = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return safe.replace(new RegExp('(' + pattern + ')', 'ig'), '<mark>$1</mark>');
    }

    // ── Open Conversation ──
    window.openConv = function(id) {
        activeConvId = id;
        document.getElementById('waChat').classList.add('visible');
        document.getElementById('waEmptyState').style.display = 'none';
        document.getElementById('waChatMessages').innerHTML = '<div class="wa-loading">Loading...</div>';

        document.querySelectorAll('.wa-conv-item').forEach(el => {
            el.classList.toggle('active', parseInt(el.dataset.id) === id);
        });

        apiFetch('/messages/conversations/' + id).then(d => {
            if (!d.success) return;
            activeConv = d.conversation;
            const name = d.conversation.customer_name || d.conversation.wa_phone;
            const qPrefix = (waQurbaniEnabled && d.conversation.is_qurbani) ? '🐐 ' : '';
            document.getElementById('waChatName').textContent = qPrefix + name;
            document.getElementById('waChatAvatar').textContent = (name || '?')[0].toUpperCase();
            let sub = d.conversation.wa_phone;
            if (d.conversation.customer_city) sub += ' · ' + d.conversation.customer_city;
            if (d.conversation.customer_orders) sub += ' · ' + d.conversation.customer_orders + ' orders';
            document.getElementById('waChatSub').textContent = sub;

            updateSessionUI(d.conversation);

            renderMessages(d.messages, d.has_more);

            // Show/hide orders toggle button and load orders if customer is linked
            const toggleBtn = document.getElementById('waOrdersToggle');
            if (d.conversation.customer_id) {
                toggleBtn.style.display = 'inline-block';
                loadOrdersPanel(d.conversation.customer_id);
            } else {
                toggleBtn.style.display = 'none';
                document.getElementById('waOrdersList').innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">No linked customer</div>';
            }
        });

        apiFetch('/messages/conversations/' + id + '/mark-read', {method:'POST'});

        if (msgPollTimer) clearInterval(msgPollTimer);
        msgPollTimer = setInterval(() => {
            if (activeConvId !== id) return;
            apiFetch('/messages/conversations/' + id).then(d => {
                if (!d.success || activeConvId !== id) return;
                activeConv = d.conversation;
                updateSessionUI(d.conversation);
                renderMessages(d.messages, d.has_more);
            });
            apiFetch('/messages/conversations/' + id + '/mark-read', {method:'POST'});
        }, POLL_INTERVAL);
    };

    function renderMessages(msgs, hasMore) {
        const el = document.getElementById('waChatMessages');
        let html = '';
        if (hasMore) {
            html += '<div class="wa-load-more"><a onclick="loadOlderMessages()">Load older messages</a></div>';
        }
        if (!msgs.length) {
            html += '<div class="wa-loading">No messages in this conversation</div>';
        }
        let lastDateKey = null;
        // Anchor the "Seen by" indicator under the LAST INBOUND message
        // (i.e. the customer's most recent note). That way the team can see
        // at a glance which colleagues have already opened the chat since
        // that message arrived — useful to avoid double-replies when the
        // customer is waiting on us.
        let lastInIdx = -1;
        for (let i = msgs.length - 1; i >= 0; i--) {
            if (msgs[i].direction === 'inbound') { lastInIdx = i; break; }
        }
        const lastInTs = lastInIdx >= 0 ? new Date(msgs[lastInIdx].created_at).getTime() : 0;
        // Only count a teammate as "seen" if they opened the thread AFTER
        // the customer's latest message — an old read timestamp from last
        // week doesn't mean they've seen the new incoming note.
        const seenSince = (activeConv && Array.isArray(activeConv.seen_by))
            ? activeConv.seen_by.filter(s => s.last_read_at && new Date(s.last_read_at).getTime() >= lastInTs)
            : [];

        msgs.forEach((m, idx) => {
            if (m.created_at) {
                const md = new Date(m.created_at);
                const dateKey = md.getFullYear() + '-' + md.getMonth() + '-' + md.getDate();
                if (dateKey !== lastDateKey) {
                    html += `<div class="wa-date-sep"><span>${fmtDateLabel(m.created_at)}</span></div>`;
                    lastDateKey = dateKey;
                }
            }
            const isOut = m.direction === 'outbound';
            const meta = (typeof m.metadata === 'string') ? JSON.parse(m.metadata || '{}') : (m.metadata || {});
            html += `<div class="wa-msg ${isOut?'out':'in'}">`;
            if (m.type === 'template') html += `<div class="wa-msg-tpl-badge">Template: ${esc(m.template_name||'')}</div>`;
            if (m.type === 'image' && m.media_url) {
                html += `<div class="wa-msg-image"><a href="${esc(m.media_url)}" target="_blank"><img src="${esc(m.media_url)}" alt="Image" style="max-width:260px;max-height:260px;border-radius:8px;display:block;cursor:pointer;" /></a></div>`;
            }
            if (m.type === 'audio') {
                if (m.media_url) {
                    html += `<div class="wa-msg-media" style="min-width:220px;"><div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;"><span style="font-size:16px;">🎤</span><span style="font-weight:600;font-size:12px;color:#374151;">Voice Note</span></div><audio controls preload="metadata" style="width:100%;max-width:280px;height:36px;" src="${esc(m.media_url)}"></audio><div style="margin-top:4px;"><a href="${esc(m.media_url)}" target="_blank" style="font-size:11px;color:#2563eb;text-decoration:none;">Download ↗</a></div></div>`;
                } else {
                    html += '<div class="wa-msg-media">🎤 Voice Note (unavailable)</div>';
                }
            }
            if (m.type === 'video') {
                if (m.media_url) html += `<div class="wa-msg-media"><a href="${esc(m.media_url)}" target="_blank" style="color:inherit;text-decoration:none;">🎬 Video (click to open)</a></div>`;
                else html += '<div class="wa-msg-media">🎬 Video</div>';
            }
            if (m.type === 'document') {
                if (m.media_url) html += `<div class="wa-msg-media"><a href="${esc(m.media_url)}" target="_blank" style="color:inherit;text-decoration:none;">📄 Document (click to open)</a></div>`;
                else html += '<div class="wa-msg-media">📄 Document</div>';
            }
            if (m.type === 'location') {
                const lat = meta.latitude, lng = meta.longitude;
                const locName = meta.name || '', locAddr = meta.address || '';
                let mapsUrl = (lat && lng) ? `https://www.google.com/maps?q=${lat},${lng}` : '';
                if (!mapsUrl && m.content) { const urlMatch = m.content.match(/(https?:\/\/[^\s]+)/); if (urlMatch) mapsUrl = urlMatch[0]; }
                html += `<div class="wa-msg-location" style="display:flex;align-items:center;gap:8px;padding:8px;background:rgba(0,0,0,0.04);border-radius:8px;margin-bottom:4px;cursor:pointer;" onclick="window.open('${mapsUrl}','_blank')">`;
                html += '<span style="font-size:24px;">📍</span><div>';
                if (locName) html += `<div style="font-weight:600;font-size:13px;">${esc(locName)}</div>`;
                if (locAddr) html += `<div style="font-size:12px;color:#6B7280;">${esc(locAddr)}</div>`;
                html += `<div style="font-size:12px;color:#2563EB;margin-top:2px;">Click to open in Maps</div>`;
                html += '</div></div>';
            }
            if (m.content && m.type !== 'location' && m.type !== 'audio') {
                const linked = esc(m.content).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" style="color:#2563EB;text-decoration:underline;">$1</a>');
                html += `<div class="wa-msg-text">${linked}</div>`;
            }
            html += `<div class="wa-msg-meta">`;
            if (m.sender_name && isOut) html += `<span class="wa-msg-sender">${esc(m.sender_name)} · </span>`;
            html += `<span class="wa-msg-time">${fmtMsgTime(m.created_at)}</span>`;
            if (isOut) html += ` <span class="wa-msg-status ${m.status==='read'?'read':''} ${m.status==='failed'?'failed':''}">${statusIcon(m.status)}</span>`;
            html += '</div>';
            if (m.status === 'failed' && m.error_message) html += `<div class="wa-msg-error">${esc(m.error_message)}</div>`;
            html += '</div>';

            // "Seen by" row under the last inbound message, left-aligned so
            // it sits below the customer's bubble. Only renders teammates
            // whose read timestamp is newer than that incoming message.
            if (idx === lastInIdx && seenSince.length > 0) {
                const names = seenSince.map(s => esc(s.name)).slice(0, 3);
                let suffix = '';
                if (seenSince.length > 3) suffix = ' +' + (seenSince.length - 3);
                html += `<div style="display:flex;justify-content:flex-start;margin:2px 0 6px 4px;font-size:10.5px;color:#6b7280;font-style:italic;">👀 Seen by ${names.join(', ')}${suffix}</div>`;
            }
        });
        el.innerHTML = html;
        el.scrollTop = el.scrollHeight;
    }

    // ── Send Message ──
    document.getElementById('waSendBtn').addEventListener('click', sendMessage);
    document.getElementById('waMessageInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    function sendMessage() {
        const input = document.getElementById('waMessageInput');
        const text = input.value.trim();
        if (!text || !activeConvId) return;

        if (activeConv && activeConv.session_expires_at && new Date(activeConv.session_expires_at) <= new Date()) {
            updateSessionUI({session_active: false, session_expires_at: null});
            if (confirm('The 24-hour window has expired. Would you like to send a template message instead?')) {
                openTemplatePicker();
            }
            return;
        }

        const btn = document.getElementById('waSendBtn');
        btn.disabled = true;

        apiFetch('/messages/conversations/' + activeConvId + '/send', {
            method: 'POST',
            body: JSON.stringify({message: text})
        }).then(d => {
            btn.disabled = false;
            if (d.success) {
                input.value = '';
                apiFetch('/messages/conversations/' + activeConvId).then(r => {
                    if (r.success) {
                        renderMessages(r.messages, r.has_more);
                        if (r.conversation) updateSessionUI(r.conversation);
                    }
                });
            } else if (d.session_expired) {
                updateSessionUI({session_active: false, session_expires_at: null});
                if (confirm('The 24-hour window has expired. Would you like to send a template message instead?')) {
                    openTemplatePicker();
                }
            } else {
                alert(d.message || 'Failed to send message');
            }
        }).catch(() => { btn.disabled = false; alert('Failed to send message'); });
    }

    // ── Back button ──
    document.getElementById('waChatBack').addEventListener('click', function() {
        activeConvId = null;
        activeConv = null;
        document.getElementById('waChat').classList.remove('visible');
        document.getElementById('waEmptyState').style.display = 'flex';
        if (msgPollTimer) clearInterval(msgPollTimer);
        loadConversations();
    });

    // ── Search ──
    document.getElementById('waSearch').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadConversations, 400);
    });

    // Search-mode toggle (Names ↔ Chats). Refresh on switch only when
    // there's actually a query to re-run, otherwise flipping the mode on
    // an empty search would hit the conversations endpoint for nothing.
    document.querySelectorAll('.wa-searchmode-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (this.dataset.mode === currentSearchMode) return;
            document.querySelectorAll('.wa-searchmode-btn').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            currentSearchMode = this.dataset.mode;
            // Update placeholder so the user can immediately see what the
            // input now does. Keeping the typed text is intentional — they
            // often toggle to re-check the same word in the other mode.
            const input = document.getElementById('waSearch');
            input.placeholder = currentSearchMode === 'chats'
                ? 'Search inside message contents…'
                : 'Search name, phone or city…';
            if ((input.value || '').trim().length > 0) {
                clearTimeout(searchTimeout);
                loadConversations();
            }
        });
    });

    // ── Filters ──
    document.querySelectorAll('.wa-filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.wa-filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            loadConversations();
        });
    });

    // ── Template Picker ──
    let tplCustomerOrders = [];

    window.openTemplatePicker = function() {
        document.getElementById('waTemplateModal').style.display = 'flex';
        document.getElementById('waTemplateList').innerHTML = '<div class="wa-loading">Loading...</div>';
        tplCustomerOrders = [];

        const fetchTpls = apiFetch('/messages/templates');
        const fetchOrders = (activeConv && activeConv.customer_id)
            ? apiFetch('/messages/customer-orders/' + activeConv.customer_id).catch(() => ({success:false}))
            : Promise.resolve({success:false});

        Promise.all([fetchTpls, fetchOrders]).then(([tplData, ordData]) => {
            if (!tplData.success) return;
            templates = tplData.templates || [];
            tplCustomerOrders = (ordData.success && ordData.orders) ? ordData.orders : [];
            renderTemplates();
        });
    };
    window.closeTemplatePicker = function() {
        document.getElementById('waTemplateModal').style.display = 'none';
    };

    function buildOrderDropdown(idx, varNum) {
        let html = `<select class="wa-tpl-param-in" data-tpl="${idx}" data-var="${varNum}" style="padding:8px 10px;">`;
        html += `<option value="">-- Select Order --</option>`;
        tplCustomerOrders.forEach(o => {
            const dt = o.order_date ? new Date(o.order_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '';
            const label = `${o.order_number} — ${dt} — Rs ${Number(o.total||0).toLocaleString()} (${o.status||''})`;
            html += `<option value="${esc(o.order_number)}">${esc(label)}</option>`;
        });
        html += `</select>`;
        return html;
    }

    function renderTemplates() {
        const el = document.getElementById('waTemplateList');
        if (!templates.length) { el.innerHTML = '<div class="wa-loading">No approved templates found</div>'; return; }
        el.innerHTML = templates.map((t, idx) => {
            let bodyText = (t.body_text||'').replace(/\\n/g, '\n');
            const custName = activeConv ? (activeConv.customer_name || '') : '';
            if (custName) bodyText = bodyText.replace('{{1}}', custName);
            let html = `<div class="wa-tpl-card">`;
            html += `<div class="wa-tpl-name">${esc(t.display_name || t.name)}</div>`;
            html += `<div class="wa-tpl-body">${esc(bodyText)}</div>`;
            if (t.has_buttons) {
                html += '<div class="wa-tpl-btns">';
                try { JSON.parse(t.button_labels||'[]').forEach(b => { html += `<span class="wa-tpl-btn-tag">${esc(b)}</span>`; }); } catch(e){}
                html += '</div>';
            }
            if (t.variable_count > 0) {
                html += '<div class="wa-tpl-params">';
                for (let i = 1; i <= t.variable_count; i++) {
                    if (i >= 2 && tplCustomerOrders.length > 0) {
                        html += `<label style="font-size:11px;color:#6b7280;margin-bottom:2px;display:block;">Variable {{${i}}} — Order Number</label>`;
                        html += buildOrderDropdown(idx, i);
                    } else {
                        const defaultVal = (i === 1 && activeConv) ? esc(activeConv.customer_name || '') : '';
                        html += `<input class="wa-tpl-param-in" data-tpl="${idx}" data-var="${i}" placeholder="Variable {{${i}}}" value="${defaultVal}" />`;
                    }
                }
                html += '</div>';
            }
            html += `<button class="wa-tpl-send" onclick="sendTemplate(${idx})">Send Template</button>`;
            html += '</div>';
            return html;
        }).join('');
    }

    window.sendTemplate = function(idx) {
        const t = templates[idx];
        if (!t || !activeConv) return;
        const params = [];
        for (let i = 1; i <= t.variable_count; i++) {
            const el = document.querySelector(`[data-tpl="${idx}"][data-var="${i}"]`);
            const val = el?.value?.trim() || '';
            if (!val) { alert('Please fill in all template variables.'); return; }
            params.push(val);
        }
        apiFetch('/messages/send-template', {
            method: 'POST',
            body: JSON.stringify({
                phone: activeConv.wa_phone,
                template_name: t.name,
                body_params: params,
                conversation_id: activeConvId || null,
                customer_id: activeConv.customer_id
            })
        }).then(d => {
            if (d.success) {
                closeTemplatePicker();
                loadConversations();
                if (activeConvId) {
                    apiFetch('/messages/conversations/' + activeConvId).then(r => {
                        if (r.success) renderMessages(r.messages, r.has_more);
                    });
                } else {
                    setTimeout(() => {
                        apiFetch('/messages/conversations?search=' + encodeURIComponent(activeConv.wa_phone)).then(r => {
                            if (r.success && r.conversations.length > 0) {
                                openConv(r.conversations[0].id);
                                loadConversations();
                            }
                        });
                    }, 500);
                }
            } else {
                alert(d.message || 'Failed to send template');
            }
        });
    };

    // ── Auto-resize textarea ──
    document.getElementById('waMessageInput').addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    // ── New Message Panel ──
    let newMsgOpen = false;
    let custSearchTimeout = null;
    window.toggleNewMessage = function() {
        newMsgOpen = !newMsgOpen;
        document.getElementById('waNewMsgPanel').classList.toggle('open', newMsgOpen);
        if (newMsgOpen) {
            document.getElementById('waCustomerSearch').value = '';
            document.getElementById('waCustomerResults').innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">Type at least 2 characters to search</div>';
            document.getElementById('waCustomerSearch').focus();
        }
    };

    document.getElementById('waCustomerSearch')?.addEventListener('input', function() {
        clearTimeout(custSearchTimeout);
        const q = this.value.trim();
        const resultsEl = document.getElementById('waCustomerResults');
        if (q.length < 2) {
            resultsEl.innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">Type at least 2 characters to search</div>';
            return;
        }
        resultsEl.innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">Searching...</div>';
        custSearchTimeout = setTimeout(() => {
            apiFetch('/api/customers/search?q=' + encodeURIComponent(q) + '&limit=15').then(d => {
                const custs = d.customers || d || [];
                if (!custs.length) {
                    resultsEl.innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">No customers found</div>';
                    return;
                }
                resultsEl.innerHTML = custs.map(c => {
                    const name = esc(c.name || '').trim() || 'Unknown';
                    const phone = esc(c.phone || '');
                    const city = c.address?.city ? ' · ' + esc(c.address.city) : '';
                    return `<div class="wa-cust-item" onclick="startConversationWith(${c.id}, '${esc(phone)}', '${esc(name)}')">
                        <div class="wa-cust-avatar">${name[0].toUpperCase()}</div>
                        <div class="wa-cust-info">
                            <div class="wa-cust-name">${name}</div>
                            <div class="wa-cust-phone">${phone}${city}</div>
                        </div>
                    </div>`;
                }).join('');
            }).catch(() => {
                resultsEl.innerHTML = '<div style="font-size:13px;color:#ef4444;padding:8px;">Search failed</div>';
            });
        }, 400);
    });

    window.startConversationWith = function(customerId, phone, customerName) {
        if (!phone) { alert('Customer has no phone number'); return; }

        // Close new message panel
        newMsgOpen = false;
        document.getElementById('waNewMsgPanel').classList.remove('open');

        apiFetch('/messages/conversations?search=' + encodeURIComponent(phone)).then(d => {
            const convs = d.conversations || [];
            const existing = convs.find(c => c.wa_phone && phone.replace(/\D/g,'').includes(c.wa_phone.replace(/\D/g,'').slice(-10)));
            if (existing) {
                openConv(existing.id);
            } else {
                activeConvId = null;
                activeConv = { wa_phone: phone, customer_id: customerId, customer_name: customerName || '' };
                document.getElementById('waChat').classList.add('visible');
                document.getElementById('waEmptyState').style.display = 'none';
                document.getElementById('waChatName').textContent = customerName || phone;
                document.getElementById('waChatAvatar').textContent = (customerName || phone || '?')[0].toUpperCase();
                document.getElementById('waChatSub').textContent = phone + ' · New conversation';
                document.getElementById('waChatMessages').innerHTML = '<div class="wa-loading"><div style="font-size:40px;">👋</div><div style="font-size:15px;color:#374151;font-weight:500;">Start a conversation</div><div style="font-size:13px;color:#6b7280;">Send a template message to begin</div></div>';
                updateSessionUI({session_active: false, session_expires_at: null});
            }
        });
    };

    // ── Template Manager ──
    window.openTemplateManager = function() {
        document.getElementById('waTemplateManager').style.display = 'flex';
        resetTemplateForm();
        loadExistingTemplates();
    };
    window.closeTemplateManager = function() {
        document.getElementById('waTemplateManager').style.display = 'none';
        resetTemplateForm();
    };

    document.getElementById('tplHasButtons')?.addEventListener('change', function() {
        document.getElementById('tplButtonLabelsDiv').style.display = this.value === '1' ? 'block' : 'none';
    });

    let _existingTemplatesById = {};
    let editingTemplateId = null;

    function loadExistingTemplates() {
        // Pass include_inactive=1 so the manager can see + re-enable templates
        // that are currently hidden from regular pickers.
        apiFetch('/messages/templates?include_inactive=1').then(d => {
            const el = document.getElementById('waExistingTemplates');
            const tpls = d.templates || [];
            _existingTemplatesById = {};
            tpls.forEach(t => { _existingTemplatesById[t.id] = t; });
            if (!tpls.length) { el.innerHTML = '<p style="color:#9ca3af;font-size:13px;">No templates added yet.</p>'; return; }
            el.innerHTML = tpls.map(t => {
                const si = (t.show_in || 'messages,orders,customers').split(',');
                const tagStyle = 'display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:500;margin-right:3px;';
                const tags = [
                    {key:'messages',label:'Messages',bg:'#dbeafe',fg:'#1e40af'},
                    {key:'orders',label:'Orders',bg:'#fef3c7',fg:'#92400e'},
                    {key:'customers',label:'Customers',bg:'#d1fae5',fg:'#065f46'},
                    {key:'shopify',label:'Shopify',bg:'#ede9fe',fg:'#5b21b6'},
                    {key:'invoice',label:'📄 Invoice',bg:'#fff7ed',fg:'#9a3412'},
                    {key:'qurbani_invoice',label:'🐄 Qurbani Invoice',bg:'#fef3c7',fg:'#b45309'}
                ].filter(x => si.includes(x.key)).map(x => `<span style="${tagStyle}background:${x.bg};color:${x.fg};">${x.label}</span>`).join('');
                const defaultBadge = t.is_default ? `<span style="${tagStyle}background:#dcfce7;color:#166534;border:1px solid #86efac;">⭐ Default</span>` : '';
                const qurbaniOnlyBadge = t.is_qurbani_only ? `<span style="${tagStyle}background:#fef3c7;color:#b45309;border:1px solid #fde68a;">🐄 Qurbani only</span>` : '';
                const regularOnlyBadge = t.is_regular_only ? `<span style="${tagStyle}background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;">🛒 Regular only</span>` : '';
                const commonBadge = (!t.is_qurbani_only && !t.is_regular_only) ? `<span style="${tagStyle}background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;">🌐 Common</span>` : '';
                // Inactive templates are visually dimmed and get a prominent badge.
                const isActive = (typeof t.is_active === 'undefined') ? true : !!t.is_active;
                const inactiveBadge = !isActive ? `<span style="${tagStyle}background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">⏸ Inactive</span>` : '';
                const inactiveRowStyle = !isActive ? 'opacity:0.55;background:#fafafa;' : '';
                const isEditingThis = editingTemplateId === t.id;
                const editBtnStyle = isEditingThis
                    ? 'padding:4px 10px;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600;'
                    : 'padding:4px 10px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;font-size:11px;cursor:pointer;';
                const toggleBtnStyle = isActive
                    ? 'padding:4px 10px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:6px;font-size:11px;cursor:pointer;'
                    : 'padding:4px 10px;background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600;';
                const toggleBtnLabel = isActive ? 'Disable' : 'Enable';
                return `<div class="wa-mgr-item" style="flex-wrap:wrap;${inactiveRowStyle}${isEditingThis?'border-color:#f59e0b;background:#fffbeb;':''}">
                    <div style="flex:1;">
                        <div class="wa-mgr-item-name">${esc(t.display_name || t.name)}</div>
                        <div class="wa-mgr-item-meta">${esc(t.name)} · ${t.variable_count} vars · ${t.status}</div>
                        <div style="margin-top:4px;">${inactiveBadge}${qurbaniOnlyBadge}${regularOnlyBadge}${commonBadge}${defaultBadge}${tags}</div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <button onclick="toggleTemplateActive(${t.id})" style="${toggleBtnStyle}" title="${isActive ? 'Hide this template from pickers' : 'Show this template in pickers again'}">${toggleBtnLabel}</button>
                        <button onclick="editFullTemplate(${t.id})" style="${editBtnStyle}">${isEditingThis ? 'Editing…' : 'Edit'}</button>
                        <button onclick="deleteTemplate(${t.id})" class="wa-mgr-del">Delete</button>
                    </div>
                </div>`;
            }).join('');
        });
    }

    // Quick toggle for is_active right from the list (no need to open edit).
    window.toggleTemplateActive = function(id) {
        const t = _existingTemplatesById[id];
        if (!t) return;
        const next = !((typeof t.is_active === 'undefined') ? true : !!t.is_active);
        apiFetch('/messages/templates/' + id, {
            method: 'PUT',
            body: JSON.stringify({ is_active: next ? 1 : 0 })
        }).then(d => {
            if (d.success) {
                _cachedApiTemplates = null;
                loadExistingTemplates();
            } else {
                alert(d.message || 'Failed to update template status');
            }
        });
    };

    window.editFullTemplate = function(id) {
        const t = _existingTemplatesById[id];
        if (!t) return;
        editingTemplateId = id;

        document.getElementById('tplName').value = t.name || '';
        document.getElementById('tplDisplayName').value = t.display_name || '';
        document.getElementById('tplBody').value = (t.body_text || '').replace(/\\n/g, '\n');
        document.getElementById('tplCategory').value = t.category || 'utility';
        document.getElementById('tplVarCount').value = t.variable_count ?? 0;
        const hasBtns = t.has_buttons ? '1' : '0';
        document.getElementById('tplHasButtons').value = hasBtns;
        document.getElementById('tplButtonLabelsDiv').style.display = hasBtns === '1' ? 'block' : 'none';
        try {
            const labels = JSON.parse(t.button_labels || '[]');
            document.getElementById('tplButtonLabels').value = Array.isArray(labels) ? labels.join(', ') : '';
        } catch (e) { document.getElementById('tplButtonLabels').value = ''; }
        document.getElementById('tplHeader').value = t.header_text || '';
        document.getElementById('tplFooter').value = t.footer_text || '';

        const si = (t.show_in || '').split(',').map(s => s.trim());
        document.getElementById('tplShowMessages').checked = si.includes('messages');
        document.getElementById('tplShowOrders').checked = si.includes('orders');
        document.getElementById('tplShowCustomers').checked = si.includes('customers');
        document.getElementById('tplShowShopify').checked = si.includes('shopify');
        document.getElementById('tplShowInvoice').checked = si.includes('invoice');
        document.getElementById('tplShowQurbaniInvoice').checked = si.includes('qurbani_invoice');
        document.getElementById('tplIsDefault').checked = !!t.is_default;
        document.getElementById('tplIsActive').checked = (typeof t.is_active === 'undefined') ? true : !!t.is_active;
        setTemplateScope(
            t.is_qurbani_only ? 'qurbani'
            : (t.is_regular_only ? 'regular' : 'common')
        );

        document.getElementById('tplFormHeading').textContent = 'Edit Template: ' + (t.display_name || t.name);
        document.getElementById('tplSaveBtn').textContent = 'Update Template';
        document.getElementById('tplCancelBtn').style.display = 'inline-block';
        document.getElementById('tplFormBanner').style.display = 'block';

        loadExistingTemplates();

        setTimeout(() => {
            document.getElementById('tplFormBanner').scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.getElementById('tplName').focus();
        }, 50);
    };

    window.cancelTemplateEdit = function() {
        resetTemplateForm();
        loadExistingTemplates();
    };

    function resetTemplateForm() {
        editingTemplateId = null;
        ['tplName','tplDisplayName','tplBody','tplHeader','tplFooter','tplButtonLabels'].forEach(id => { var el = document.getElementById(id); if(el) el.value = ''; });
        document.getElementById('tplVarCount').value = '0';
        document.getElementById('tplHasButtons').value = '0';
        document.getElementById('tplButtonLabelsDiv').style.display = 'none';
        document.getElementById('tplCategory').value = 'utility';
        document.getElementById('tplShowMessages').checked = true;
        document.getElementById('tplShowOrders').checked = true;
        document.getElementById('tplShowCustomers').checked = true;
        document.getElementById('tplShowShopify').checked = true;
        document.getElementById('tplShowInvoice').checked = false;
        document.getElementById('tplShowQurbaniInvoice').checked = false;
        document.getElementById('tplIsDefault').checked = false;
        document.getElementById('tplIsActive').checked = true;
        setTemplateScope('common');
        document.getElementById('tplFormHeading').textContent = 'Add New Template';
        document.getElementById('tplSaveBtn').textContent = 'Save Template';
        document.getElementById('tplCancelBtn').style.display = 'none';
        document.getElementById('tplFormBanner').style.display = 'none';
    }

    // --- Template scope (Common / Regular-only / Qurbani-only) ---------
    // The three radio buttons map to two backend booleans:
    //   common  → is_qurbani_only=0, is_regular_only=0   (shows everywhere)
    //   regular → is_qurbani_only=0, is_regular_only=1   (hidden on Qurbani)
    //   qurbani → is_qurbani_only=1, is_regular_only=0   (hidden on Regular)
    function getTemplateScope() {
        const r = document.querySelector('input[name="tplScope"]:checked');
        return r ? r.value : 'common';
    }
    function setTemplateScope(scope) {
        const valid = ['common','regular','qurbani'].includes(scope) ? scope : 'common';
        const radio = document.querySelector('input[name="tplScope"][value="'+valid+'"]');
        if (radio) radio.checked = true;
        refreshTemplateScopeUI();
    }
    function refreshTemplateScopeUI() {
        const selected = getTemplateScope();
        document.querySelectorAll('.tpl-scope-opt').forEach(el => {
            el.classList.toggle('tpl-scope-selected', el.getAttribute('data-scope') === selected);
        });
    }
    // Attach listeners once the manager modal is rendered (the form exists at page load).
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="tplScope"]').forEach(r => {
            r.addEventListener('change', refreshTemplateScopeUI);
        });
        refreshTemplateScopeUI();
    });

    function getShowInValue() {
        const parts = [];
        if (document.getElementById('tplShowMessages').checked) parts.push('messages');
        if (document.getElementById('tplShowOrders').checked) parts.push('orders');
        if (document.getElementById('tplShowCustomers').checked) parts.push('customers');
        if (document.getElementById('tplShowShopify').checked) parts.push('shopify');
        if (document.getElementById('tplShowInvoice').checked) parts.push('invoice');
        if (document.getElementById('tplShowQurbaniInvoice').checked) parts.push('qurbani_invoice');
        return parts.length ? parts.join(',') : 'messages';
    }

    window.saveTemplateForm = function() {
        const name = document.getElementById('tplName').value.trim();
        const displayName = document.getElementById('tplDisplayName').value.trim();
        const body = document.getElementById('tplBody').value.trim();
        if (!name || !displayName || !body) { alert('Please fill in Template Name, Display Name, and Body Text.'); return; }

        const hasButtons = document.getElementById('tplHasButtons').value === '1';
        const buttonLabels = hasButtons
            ? document.getElementById('tplButtonLabels').value.split(',').map(s => s.trim()).filter(Boolean)
            : [];

        const payload = {
            name: name,
            display_name: displayName,
            body_text: body,
            category: document.getElementById('tplCategory').value,
            variable_count: parseInt(document.getElementById('tplVarCount').value) || 0,
            has_buttons: hasButtons ? 1 : 0,
            button_labels: buttonLabels,
            header_text: document.getElementById('tplHeader').value.trim(),
            footer_text: document.getElementById('tplFooter').value.trim(),
            show_in: getShowInValue(),
            is_default: document.getElementById('tplIsDefault').checked ? 1 : 0,
            is_active: document.getElementById('tplIsActive').checked ? 1 : 0,
            is_qurbani_only: getTemplateScope() === 'qurbani' ? 1 : 0,
            is_regular_only: getTemplateScope() === 'regular' ? 1 : 0
        };

        const isEdit = editingTemplateId !== null;
        const url = isEdit ? '/messages/templates/' + editingTemplateId : '/messages/templates';
        const method = isEdit ? 'PUT' : 'POST';

        const btn = document.getElementById('tplSaveBtn');
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = isEdit ? 'Updating…' : 'Saving…';

        apiFetch(url, { method: method, body: JSON.stringify(payload) }).then(d => {
            btn.disabled = false;
            btn.textContent = origText;
            if (d.success) {
                _cachedApiTemplates = null;
                resetTemplateForm();
                loadExistingTemplates();
            } else {
                alert(d.message || 'Failed to save template');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Failed to save template');
        });
    };

    window.deleteTemplate = function(id) {
        if (!confirm('Delete this template?')) return;
        apiFetch('/messages/templates/' + id, {method: 'DELETE'}).then(d => {
            if (d.success) { _cachedApiTemplates = null; loadExistingTemplates(); }
            else alert(d.message || 'Failed to delete');
        }).catch(() => alert('Failed to delete'));
    };

    // ── Orders Panel (right sidebar) ──
    let ordersPanelOpen = localStorage.getItem('waOrdersPanel') !== 'closed';

    window.toggleOrdersPanel = function() {
        const panel = document.getElementById('waOrdersPanel');
        ordersPanelOpen = !panel.classList.contains('open');
        panel.classList.toggle('open', ordersPanelOpen);
        localStorage.setItem('waOrdersPanel', ordersPanelOpen ? 'open' : 'closed');
    };

    function loadOrdersPanel(customerId) {
        const list = document.getElementById('waOrdersList');
        list.innerHTML = '<div style="padding:20px;text-align:center;"><div style="width:20px;height:20px;border:2px solid #e5e7eb;border-top-color:#16a34a;border-radius:50%;animation:spin 0.6s linear infinite;margin:0 auto;"></div></div>';

        apiFetch('/messages/customer-orders/' + customerId).then(d => {
            if (!d.success || !d.orders || !d.orders.length) {
                list.innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">No orders found</div>';
                return;
            }
            let html = '';
            d.orders.forEach(o => {
                const dt = o.order_date ? new Date(o.order_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '';
                const st = (o.status || 'pending').toLowerCase().replace(/\s+/g, '_');
                const statusLabel = (o.status || 'Pending').replace(/_/g, ' ');
                html += `<div class="wa-op-item">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div><span class="wa-op-num">#${esc(o.order_number||'')}</span><span class="wa-op-date">${dt}</span></div>
                        <span class="wa-op-total">Rs. ${parseFloat(o.total||0).toLocaleString()}</span>
                    </div>
                    <div style="margin-top:4px;display:flex;align-items:center;gap:6px;">
                        <span class="wa-op-status ${st}">${statusLabel}</span>
                        ${o.items_count ? '<span style="font-size:10px;color:#9ca3af;">' + o.items_count + ' items</span>' : ''}
                    </div>
                    ${o.rider_name ? '<div class="wa-op-rider">🏍️ ' + esc(o.rider_name) + (o.eta ? ' · ETA ' + o.eta : '') + '</div>' : (o.eta ? '<div class="wa-op-rider">⏱️ ETA ' + o.eta + '</div>' : '')}
                    ${o.items_summary ? '<div class="wa-op-items">' + esc(o.items_summary) + '</div>' : ''}
                    <button class="wa-op-inv-btn" onclick="openInvoiceFromPanel(${o.id}, '${esc(o.order_number||'')}', ${parseFloat(o.total||0)})">📄 Send Invoice</button>
                </div>`;
            });
            list.innerHTML = html;
        }).catch(() => {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#dc2626;font-size:13px;">Failed to load</div>';
        });

        if (ordersPanelOpen) {
            document.getElementById('waOrdersPanel').classList.add('open');
        }
    }

    window.openInvoiceFromPanel = function(orderId, orderNum, total) {
        if (!activeConv) return;
        // Open the invoice picker modal pre-filled
        let modal = document.getElementById('waInvoicePickerModal');
        if (modal) modal.remove();

        const custName = activeConv.customer_name || '';
        modal = document.createElement('div');
        modal.id = 'waInvoicePickerModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = `
            <div style="background:#fff;border-radius:12px;width:520px;max-width:95vw;max-height:85vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="padding:14px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-weight:700;font-size:15px;">📄 Send Invoice #${orderNum}</span>
                    <button onclick="document.getElementById('waInvoicePickerModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;">&times;</button>
                </div>
                <div style="padding:16px 20px;" id="waInvPickerBody"></div>
            </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
        selectInvoiceOrder(orderId, orderNum, total);
    };

    // ── Invoice Picker ──
    window.openInvoicePicker = function() {
        if (!activeConv || !activeConv.customer_id) {
            alert('This conversation has no linked customer. Cannot look up invoices.');
            return;
        }
        let modal = document.getElementById('waInvoicePickerModal');
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.id = 'waInvoicePickerModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = `
            <div style="background:#fff;border-radius:12px;width:520px;max-width:95vw;max-height:85vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="padding:14px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-weight:700;font-size:15px;">📄 Send Invoice — ${esc(activeConv.customer_name || '')}</span>
                    <button onclick="document.getElementById('waInvoicePickerModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;">&times;</button>
                </div>
                <div style="padding:16px 20px;" id="waInvPickerBody">
                    <div style="text-align:center;padding:20px;color:#6b7280;">Loading orders...</div>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });

        apiFetch('/messages/customer-orders/' + activeConv.customer_id).then(d => {
            const body = document.getElementById('waInvPickerBody');
            if (!d.success || !d.orders.length) {
                body.innerHTML = '<div style="text-align:center;padding:20px;color:#6b7280;">No orders found for this customer.</div>';
                return;
            }
            let html = '<div style="margin-bottom:12px;font-size:13px;color:#6b7280;">Select an order to send its invoice:</div>';
            d.orders.forEach(o => {
                const dt = o.order_date ? new Date(o.order_date).toLocaleDateString() : '';
                html += `<div onclick="selectInvoiceOrder(${o.id}, '${esc(o.order_number)}', ${parseFloat(o.total||0)})" style="padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='#fff'">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <span style="font-weight:600;font-size:14px;color:#111827;">#${esc(o.order_number)}</span>
                            <span style="font-size:12px;color:#6b7280;margin-left:8px;">${dt}</span>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-weight:600;font-size:14px;color:#111827;">Rs. ${parseFloat(o.total||0).toLocaleString()}</div>
                            <div style="font-size:11px;color:#6b7280;">${o.items_count} item(s)</div>
                        </div>
                    </div>
                    ${o.items_summary ? '<div style="font-size:11px;color:#9ca3af;margin-top:4px;">' + esc(o.items_summary) + '</div>' : ''}
                </div>`;
            });
            body.innerHTML = html;
        }).catch(e => {
            document.getElementById('waInvPickerBody').innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;">Failed to load orders.</div>';
        });
    };

    window.selectInvoiceOrder = function(orderId, orderNum, total) {
        const body = document.getElementById('waInvPickerBody');
        const custName = activeConv ? activeConv.customer_name : '';
        body.innerHTML = `
            <div style="margin-bottom:12px;">
                <div style="font-size:12px;color:#6b7280;">Order</div>
                <div style="font-weight:600;font-size:15px;">#${orderNum} — Rs. ${total.toLocaleString()}</div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Invoice Template Name</label>
                <input id="waInvTplName" type="text" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" placeholder="e.g. send_invoice" />
                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Must match the approved template in Meta Business Suite</div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Body Variables (comma-separated)</label>
                <input id="waInvTplParams" type="text" value="${custName}, ${orderNum}" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
            </div>
            <div id="waInvPickerPreview" style="margin-bottom:12px;display:none;">
                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Invoice Preview</label>
                <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:center;background:#f9fafb;padding:6px;">
                    <img id="waInvPickerPreviewImg" style="max-width:100%;max-height:250px;border-radius:4px;cursor:pointer;" onclick="openFullscreenImg(this.src)" title="Click to view full size" />
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button id="waInvPickerPrevBtn" onclick="previewInvPicker(${orderId})" style="flex:1;padding:9px;border:1px solid #d97706;color:#d97706;background:#fff;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Preview</button>
                <button id="waInvPickerSendBtn" onclick="sendInvPicker(${orderId})" style="flex:1;padding:9px;background:#25D366;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Send Invoice</button>
            </div>
            <div id="waInvPickerStatus" style="margin-top:8px;font-size:13px;text-align:center;display:none;"></div>`;

        apiFetch('/messages/templates?context=invoice').then(d => {
            if (d.success && d.templates && d.templates.length) {
                const el = document.getElementById('waInvTplName');
                if (el && !el.value) el.value = d.templates[0].name;
            }
        }).catch(() => {});

        // Auto-generate the preview image on order select so the user sees
        // the invoice immediately and the Send button activates. Without
        // this, Send stays disabled (because the template has an image
        // header that must be generated first) and it's not obvious why.
        setTimeout(() => { try { previewInvPicker(orderId); } catch (e) {} }, 50);
    };

    function captureInvoiceImage(invoiceUrl, orderId) {
        return new Promise((resolve, reject) => {
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:900px;height:1400px;border:none;opacity:0;';
            document.body.appendChild(iframe);
            iframe.src = invoiceUrl;
            iframe.onload = async function() {
                try {
                    const addScript = (doc, src) => new Promise(r => { const s = doc.createElement('script'); s.src = src; s.onload = r; doc.head.appendChild(s); });
                    const iDoc = iframe.contentDocument || iframe.contentWindow.document;
                    await addScript(iDoc, 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
                    const node = iDoc.querySelector('.invoice-container');
                    if (!node) { iframe.remove(); reject(new Error('Invoice container not found')); return; }
                    const canvas = await iframe.contentWindow.html2canvas(node, {scale: 2, useCORS: true, allowTaint: true});
                    const dataUrl = canvas.toDataURL('image/png');
                    iframe.remove();

                    const uploadRes = await apiFetch('/messages/upload-invoice-image', {
                        method: 'POST',
                        body: JSON.stringify({ order_id: orderId, image_data: dataUrl })
                    });
                    if (uploadRes.success) {
                        resolve(uploadRes);
                    } else {
                        reject(new Error(uploadRes.message || 'Upload failed'));
                    }
                } catch (err) { iframe.remove(); reject(err); }
            };
            iframe.onerror = function() { iframe.remove(); reject(new Error('Failed to load invoice')); };
        });
    }

    window.previewInvPicker = function(orderId) {
        const btn = document.getElementById('waInvPickerPrevBtn');
        btn.textContent = 'Loading...'; btn.disabled = true;
        apiFetch('/messages/invoice-image/' + orderId).then(d => {
            if (!d.success) { alert(d.message || 'Failed'); btn.textContent = 'Preview'; btn.disabled = false; return; }

            if (d.needs_capture) {
                captureInvoiceImage(d.invoice_url, orderId).then(uploadRes => {
                    document.getElementById('waInvPickerPreviewImg').src = uploadRes.image_url;
                    document.getElementById('waInvPickerPreview').style.display = 'block';
                    document.getElementById('waInvPickerSendBtn').disabled = false;
                    btn.textContent = 'Refresh'; btn.disabled = false;
                }).catch(err => { alert('Failed to capture invoice: ' + err.message); btn.textContent = 'Preview'; btn.disabled = false; });
            } else {
                document.getElementById('waInvPickerPreviewImg').src = d.image_url;
                document.getElementById('waInvPickerPreview').style.display = 'block';
                document.getElementById('waInvPickerSendBtn').disabled = false;
                btn.textContent = 'Refresh'; btn.disabled = false;
            }
        }).catch(() => { btn.textContent = 'Preview'; btn.disabled = false; });
    };

    window.sendInvPicker = function(orderId) {
        const tplName = document.getElementById('waInvTplName').value.trim();
        if (!tplName) { alert('Please enter the template name'); return; }
        const paramsStr = document.getElementById('waInvTplParams').value.trim();
        const bodyParams = paramsStr ? paramsStr.split(',').map(s => s.trim()) : [];
        const phone = activeConv ? activeConv.wa_phone : '';
        if (!phone) { alert('No phone number'); return; }

        const btn = document.getElementById('waInvPickerSendBtn');
        const status = document.getElementById('waInvPickerStatus');
        btn.textContent = 'Sending...'; btn.disabled = true; status.style.display = 'none';

        // Safety net: if the preview image hasn't been generated yet (the
        // auto-preview may still be running, or it failed), run the capture
        // flow synchronously before sending so we don't hit a silent
        // "needs_capture" 422 from the backend.
        const previewImg = document.getElementById('waInvPickerPreviewImg');
        const previewReady = previewImg && previewImg.src && !previewImg.src.endsWith('#');
        const ensurePreview = previewReady
            ? Promise.resolve(true)
            : apiFetch('/messages/invoice-image/' + orderId).then(d => {
                if (!d.success) throw new Error(d.message || 'Failed to generate invoice image');
                if (d.needs_capture) {
                    return captureInvoiceImage(d.invoice_url, orderId).then(uploadRes => {
                        if (previewImg) previewImg.src = uploadRes.image_url;
                        const prevBox = document.getElementById('waInvPickerPreview');
                        if (prevBox) prevBox.style.display = 'block';
                        return true;
                    });
                }
                if (previewImg) previewImg.src = d.image_url;
                const prevBox = document.getElementById('waInvPickerPreview');
                if (prevBox) prevBox.style.display = 'block';
                return true;
            });

        ensurePreview.then(() => apiFetch('/messages/send-invoice', {
            method: 'POST',
            body: JSON.stringify({ order_id: orderId, phone: phone, template_name: tplName, body_params: bodyParams, conversation_id: activeConvId })
        })).then(d => {
            if (d.success) {
                status.style.display = 'block'; status.style.color = '#16a34a'; status.textContent = 'Invoice sent!';
                btn.textContent = 'Sent!';
                setTimeout(() => {
                    document.getElementById('waInvoicePickerModal')?.remove();
                    if (activeConvId) apiFetch('/messages/conversations/' + activeConvId).then(r => { if (r.success) renderMessages(r.messages, r.has_more); });
                    loadConversations();
                }, 1500);
            } else { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = d.message || 'Failed'; btn.textContent = 'Send Invoice'; btn.disabled = false; }
        }).catch(e => { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = e.message || 'Failed'; btn.textContent = 'Send Invoice'; btn.disabled = false; });
    };

    window.openFullscreenImg = function(src) {
        if (!src) return;
        let overlay = document.getElementById('waImgOverlay');
        if (overlay) overlay.remove();
        overlay = document.createElement('div');
        overlay.id = 'waImgOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
        overlay.innerHTML = '<img src="' + src + '" style="max-width:92vw;max-height:92vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.5);" /><button style="position:absolute;top:16px;right:24px;background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:28px;cursor:pointer;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;">&times;</button>';
        overlay.addEventListener('click', function() { overlay.remove(); });
        document.body.appendChild(overlay);
    };

    // ═══════════════════════════════════════════════════════════════════
    // LABELS + 3-DOT MENU (Phase 1)
    //
    // Responsibilities:
    //   • Fetch the workspace label library lazily (first time the labels
    //     modal or filter dropdown opens). Cached in `allLabels` for the
    //     rest of the session until we bust it after create/update/delete.
    //   • Render label chips on inbox rows and in the chat header.
    //   • Apply / remove labels on the active conversation.
    //   • Library CRUD (gated on `can_manage` from the API response).
    //   • "Mark Unread" — hits the new markUnread endpoint and reloads.
    // ═══════════════════════════════════════════════════════════════════
    let allLabels = null;     // { labels:[…], can_manage:bool }
    let labelFilterId = null; // active filter id or null

    function labelTextColor(hex) {
        // Pick white or dark text for a given hex colour by relative
        // luminance. Handles both 3- and 6-digit hexes. Fallback to dark.
        if (!hex || typeof hex !== 'string') return '#111827';
        let h = hex.trim().replace('#','');
        if (h.length === 3) h = h.split('').map(c => c+c).join('');
        if (h.length !== 6) return '#111827';
        const r = parseInt(h.slice(0,2), 16);
        const g = parseInt(h.slice(2,4), 16);
        const b = parseInt(h.slice(4,6), 16);
        const yiq = (r*299 + g*587 + b*114) / 1000;
        return yiq >= 160 ? '#111827' : '#fff';
    }
    function chipBackground(hex) {
        // Light pastel fill for inbox rows (10% hex) falling back to
        // grey, plus a matching coloured border for definition.
        if (!hex) return { bg: '#f3f4f6', color: '#374151', border: '#e5e7eb' };
        return { bg: hex + '26', color: '#111827', border: hex + '80' };
    }
    function renderConvLabels(labels) {
        if (!labels || !labels.length) return '';
        const shown = labels.slice(0, 2);
        const extra = labels.length - shown.length;
        const chips = shown.map(l => {
            const c = chipBackground(l.color);
            return `<span class="wa-label-chip" title="${esc(l.name)}" style="background:${c.bg};color:${c.color};border:1px solid ${c.border};">${esc(l.name)}</span>`;
        }).join('');
        const more = extra > 0 ? `<span class="wa-label-chip" style="background:#e5e7eb;color:#374151;">+${extra}</span>` : '';
        return `<div class="wa-conv-labels">${chips}${more}</div>`;
    }
    function renderChatHeaderLabels(labels) {
        const wrap = document.getElementById('waChatHdrLabels');
        if (!wrap) return;
        if (!labels || !labels.length) { wrap.innerHTML = ''; return; }
        wrap.innerHTML = labels.map(l => {
            const c = chipBackground(l.color);
            return `<span class="wa-label-chip" style="background:${c.bg};color:${c.color};border:1px solid ${c.border};">
                ${esc(l.name)}
                <button class="wa-label-remove" title="Remove label" onclick="removeLabelFromActive(${l.id})">&times;</button>
            </span>`;
        }).join('');
    }

    // Hook into renderConversations — we *extend* the existing markup by
    // injecting the labels row after city. Easier than rewriting the whole
    // function, and keeps the existing chat-search snippet logic intact.
    const _origRenderConversations = renderConversations;
    renderConversations = function(convs, opts) {
        _origRenderConversations(convs, opts);
        // After the base render, walk the list and inject a labels strip
        // for each row (if the conversation has any labels).
        document.querySelectorAll('#waConvList .wa-conv-item').forEach(el => {
            const id = parseInt(el.dataset.id || '0', 10);
            const conv = convs.find(c => c.id === id);
            if (!conv) return;
            // Labels strip.
            if (conv.labels && conv.labels.length && !el.querySelector('.wa-conv-labels')) {
                const info = el.querySelector('.wa-conv-info');
                if (info) info.insertAdjacentHTML('beforeend', renderConvLabels(conv.labels));
            }
            // Phase 2 — mention dot. Injected at the end of the bottom row
            // right after the unread badge so both "needs attention" chips
            // line up. Only added when the CURRENT user has unread mentions
            // on this convo.
            if ((conv.mentions_count || 0) > 0 && !el.querySelector('.wa-mention-dot')) {
                const bottom = el.querySelector('.wa-conv-bottom') || el.querySelector('.wa-conv-info');
                if (bottom) bottom.insertAdjacentHTML('beforeend',
                    '<span class="wa-mention-dot" title="You were tagged on this conversation">@</span>');
            }
        });
    };

    // Hook into openConv — after the detail call resolves, push the
    // conversation's labels into the header strip. The original openConv
    // already sets activeConv when the promise resolves, so we rely on
    // a tiny poll to detect that happened and paint the strip. We keep
    // this simple by overriding window.openConv; preserving all the
    // existing behaviour by calling through.
    const _origOpenConv = window.openConv;
    window.openConv = function(id) {
        renderChatHeaderLabels([]); // clear while loading
        _origOpenConv(id);
        // activeConv is set asynchronously inside _origOpenConv. Poll once
        // shortly after to paint header labels. 400ms is enough for a
        // typical detail fetch; worst case we'll reconcile on next poll.
        setTimeout(() => {
            if (activeConv && activeConv.id === id) {
                renderChatHeaderLabels(activeConv.labels || []);
            }
        }, 400);
    };

    // Also refresh the header-label strip every poll tick so changes
    // applied by other users eventually show up here (we're already
    // refetching the detail in the existing msgPollTimer → the
    // updateSessionUI call) — hook that by reading activeConv.
    setInterval(() => {
        if (activeConv && activeConv.id === activeConvId) {
            renderChatHeaderLabels(activeConv.labels || []);
        }
    }, POLL_INTERVAL);

    // ── 3-dot menu ──
    window.toggleChatMenu = function() {
        document.getElementById('waChatMenu').classList.toggle('open');
    };
    // Close menu on outside click.
    document.addEventListener('click', function(e) {
        const menu = document.getElementById('waChatMenu');
        const btn = document.getElementById('waChatMenuBtn');
        if (menu && menu.classList.contains('open') && !menu.contains(e.target) && e.target !== btn) {
            menu.classList.remove('open');
        }
        const lf = document.getElementById('waLabelFilterMenu');
        const lfBtn = document.getElementById('waLabelFilterBtn');
        if (lf && lf.classList.contains('open') && !lf.contains(e.target) && e.target !== lfBtn) {
            lf.classList.remove('open');
        }
    });

    window.doMarkUnread = function() {
        document.getElementById('waChatMenu').classList.remove('open');
        if (!activeConvId) return;
        apiFetch('/messages/conversations/' + activeConvId + '/mark-unread', { method: 'POST' }).then(d => {
            if (d.success) {
                // Drop out of this conversation so the unread badge re-appears
                // on the left, and reload the list so the styling updates.
                const prevId = activeConvId;
                activeConvId = null;
                activeConv = null;
                const panel = document.getElementById('waChat');
                if (panel) panel.classList.remove('visible');
                const empty = document.getElementById('waEmptyState');
                if (empty) empty.style.display = 'flex';
                loadConversations();
                if (window.toastr && toastr.success) toastr.success('Marked as unread');
            } else if (window.toastr && toastr.error) {
                toastr.error(d.message || 'Failed to mark unread');
            }
        });
    };

    // ── Labels modal ──
    window.openLabelsModal = function() {
        document.getElementById('waChatMenu').classList.remove('open');
        if (!activeConvId) return;
        document.getElementById('waLabelsModal').classList.add('open');
        renderLabelsModal();
    };
    window.closeLabelsModal = function() {
        document.getElementById('waLabelsModal').classList.remove('open');
    };

    function fetchLabels(force) {
        if (allLabels && !force) return Promise.resolve(allLabels);
        return apiFetch('/messages/labels').then(d => {
            if (!d.success) return { labels: [], can_manage: false };
            allLabels = d;
            return d;
        });
    }

    function renderLabelsModal() {
        const togglesEl = document.getElementById('waLabelToggles');
        const manageWrap = document.getElementById('waLabelManageBlock');
        const manageList = document.getElementById('waLabelManageList');
        togglesEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;">Loading…</div>';
        manageWrap.style.display = 'none';

        Promise.all([
            fetchLabels(true),
            apiFetch('/messages/conversations/' + activeConvId), // to get current labels
        ]).then(([lib, det]) => {
            const applied = (det && det.conversation && det.conversation.labels) || [];
            const appliedIds = new Set(applied.map(l => l.id));
            // Keep activeConv in sync so the header strip is correct.
            if (det && det.conversation) {
                activeConv = det.conversation;
                renderChatHeaderLabels(activeConv.labels || []);
            }

            if (!lib.labels.length) {
                togglesEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;">No labels yet. ' +
                    (lib.can_manage ? 'Create one below.' : 'Ask an admin to create labels.') + '</div>';
            } else {
                togglesEl.innerHTML = lib.labels.map(l => {
                    const on = appliedIds.has(l.id);
                    const style = on
                        ? `background:${l.color}26;color:${l.color === '#FFFFFF' || l.color === '#fff' ? '#111827' : l.color};border-color:${l.color};`
                        : '';
                    const mentionPrefix = l.user_id ? '<span class="wa-label-mention-tag">@</span>' : '';
                    return `<span class="wa-label-toggle ${on ? 'applied' : ''}" style="${style}" onclick="toggleLabelOnActive(${l.id}, ${on ? 1 : 0})">
                        <span class="wa-label-color-dot" style="background:${l.color}"></span>
                        ${mentionPrefix}${esc(l.name)}
                    </span>`;
                }).join('');
            }

            if (lib.can_manage) {
                manageWrap.style.display = 'block';
                // Populate the user-mention picker ONCE per modal open.
                // Server gates this endpoint by manage_whatsapp_labels, so
                // if we got can_manage we know it will succeed.
                populateLabelUserPicker();
                if (!lib.labels.length) {
                    manageList.innerHTML = '<div style="font-size:12px;color:#9ca3af;padding:6px 0;">No labels yet.</div>';
                } else {
                    manageList.innerHTML = lib.labels.map(l => `
                        <div class="wa-label-manage-row" data-lid="${l.id}">
                            <span class="wa-label-color-dot" style="background:${l.color}"></span>
                            <span style="font-size:13px;color:#111827;font-weight:500;">${esc(l.name)}</span>
                            ${l.user_id ? '<span class="wa-label-mention-badge" title="Applying this label pings the tagged user">@mention</span>' : ''}
                            ${l.is_system ? '<span style="font-size:10px;color:#9ca3af;margin-left:4px;">system</span>' : ''}
                            <div class="wa-label-manage-actions">
                                <button title="Rename" onclick="renameLabel(${l.id})">✏️</button>
                                <button title="Delete" onclick="deleteLabelClick(${l.id}, ${l.is_system ? 1 : 0})">🗑️</button>
                            </div>
                        </div>
                    `).join('');
                }
            }
        });
    }

    // Phase 2: fill the user-mention picker dropdown with eligible staff.
    // Cached between renders; re-fetched only if cache is empty.
    let _labelUsersCache = null;
    function populateLabelUserPicker() {
        const sel = document.getElementById('waLabelNewUser');
        if (!sel) return;
        if (_labelUsersCache) {
            _renderLabelUserOptions(sel, _labelUsersCache);
            return;
        }
        apiFetch('/messages/label-users').then(d => {
            if (!d || !d.success) return;
            _labelUsersCache = d.users || [];
            _renderLabelUserOptions(sel, _labelUsersCache);
        });
    }
    function _renderLabelUserOptions(sel, users) {
        // Preserve any already-selected value across re-renders.
        const prev = sel.value;
        sel.innerHTML = '<option value="">No user (generic)</option>' +
            users.map(u => `<option value="${u.id}">@ ${esc(u.fullname || ('User #' + u.id))}</option>`).join('');
        if (prev) sel.value = prev;
    }

    window.toggleLabelOnActive = function(labelId, isApplied) {
        if (!activeConvId) return;
        if (isApplied) {
            apiFetch('/messages/conversations/' + activeConvId + '/labels/' + labelId, { method: 'DELETE' }).then(d => {
                if (!d.success) return;
                if (activeConv) activeConv.labels = d.labels;
                renderChatHeaderLabels(d.labels || []);
                renderLabelsModal(); // re-render toggles
                loadConversations(); // refresh inbox row chips
            });
        } else {
            apiFetch('/messages/conversations/' + activeConvId + '/labels', {
                method: 'POST', body: JSON.stringify({ label_id: labelId }),
            }).then(d => {
                if (!d.success) return;
                if (activeConv) activeConv.labels = d.labels;
                renderChatHeaderLabels(d.labels || []);
                renderLabelsModal();
                loadConversations();
            });
        }
    };

    window.removeLabelFromActive = function(labelId) {
        if (!activeConvId) return;
        apiFetch('/messages/conversations/' + activeConvId + '/labels/' + labelId, { method: 'DELETE' }).then(d => {
            if (!d.success) return;
            if (activeConv) activeConv.labels = d.labels;
            renderChatHeaderLabels(d.labels || []);
            loadConversations();
        });
    };

    window.createLabel = function() {
        const name = (document.getElementById('waLabelNewName').value || '').trim();
        const color = document.getElementById('waLabelNewColor').value || '#16A34A';
        const userEl = document.getElementById('waLabelNewUser');
        const userId = userEl && userEl.value ? parseInt(userEl.value, 10) : null;
        if (!name) { alert('Please enter a label name.'); return; }
        const body = { name, color };
        if (userId) body.user_id = userId;
        apiFetch('/messages/labels', {
            method: 'POST', body: JSON.stringify(body),
        }).then(d => {
            if (!d.success) { alert(d.message || 'Failed to create label'); return; }
            document.getElementById('waLabelNewName').value = '';
            if (userEl) userEl.value = '';
            allLabels = null;
            renderLabelsModal();
            loadConversations();
            loadLabelFilterMenu(true);
        });
    };

    window.renameLabel = function(id) {
        const newName = prompt('New label name:');
        if (!newName) return;
        apiFetch('/messages/labels/' + id, {
            method: 'PUT', body: JSON.stringify({ name: newName.trim() }),
        }).then(d => {
            if (!d.success) { alert(d.message || 'Failed to rename'); return; }
            allLabels = null;
            renderLabelsModal();
            loadConversations();
            loadLabelFilterMenu(true);
            // If the active conv had this label, refresh its header strip.
            if (activeConv && (activeConv.labels || []).some(l => l.id === id)) {
                apiFetch('/messages/conversations/' + activeConvId).then(d2 => {
                    if (d2.success) { activeConv = d2.conversation; renderChatHeaderLabels(activeConv.labels || []); }
                });
            }
        });
    };

    window.deleteLabelClick = function(id, isSystem) {
        const warn = isSystem
            ? 'This is a system label — are you SURE you want to delete it from every conversation?'
            : 'Delete this label? It will be removed from every conversation that has it.';
        if (!confirm(warn)) return;
        apiFetch('/messages/labels/' + id, { method: 'DELETE' }).then(d => {
            if (!d.success) { alert(d.message || 'Failed to delete'); return; }
            allLabels = null;
            renderLabelsModal();
            loadConversations();
            loadLabelFilterMenu(true);
            if (labelFilterId === id) { labelFilterId = null; }
            if (activeConvId) {
                apiFetch('/messages/conversations/' + activeConvId).then(d2 => {
                    if (d2.success) { activeConv = d2.conversation; renderChatHeaderLabels(activeConv.labels || []); }
                });
            }
        });
    };

    // ── Label filter on inbox ──
    window.toggleLabelFilter = function() {
        const menu = document.getElementById('waLabelFilterMenu');
        const opening = !menu.classList.contains('open');
        menu.classList.toggle('open');
        if (opening) loadLabelFilterMenu(false);
    };
    function loadLabelFilterMenu(force) {
        fetchLabels(force).then(lib => {
            const menu = document.getElementById('waLabelFilterMenu');
            if (!menu) return;
            const items = [
                `<button class="${labelFilterId===null?'active':''}" onclick="setLabelFilter(null)">All conversations</button>`,
                ...lib.labels.map(l => `
                    <button class="${labelFilterId===l.id?'active':''}" onclick="setLabelFilter(${l.id})">
                        <span class="wa-label-color-dot" style="background:${l.color}"></span>
                        ${esc(l.name)}
                    </button>
                `),
            ];
            menu.innerHTML = items.join('');
        });
    }
    window.setLabelFilter = function(id) {
        labelFilterId = id;
        const btn = document.getElementById('waLabelFilterBtn');
        const menu = document.getElementById('waLabelFilterMenu');
        if (menu) menu.classList.remove('open');
        if (btn) {
            if (id) {
                btn.classList.add('active');
                const lib = allLabels || { labels: [] };
                const l = lib.labels.find(x => x.id === id);
                btn.textContent = '🏷️ ' + (l ? l.name : 'Label');
            } else {
                btn.classList.remove('active');
                btn.textContent = '🏷️ Label';
            }
        }
        loadConversations();
    };

    // Hook loadConversations once more — append label_id / assigned_to_me to
    // the URL if either filter is active. When neither is set we just defer
    // to the original implementation so we don't run two requests in a row.
    const _origLoadConversations = loadConversations;
    loadConversations = function() {
        if (!labelFilterId && !assignedToMe) {
            _origLoadConversations();
            return;
        }
        let url = '/messages/conversations?filter=' + currentFilter;
        const searchInput = document.getElementById('waSearch');
        const search = (searchInput && searchInput.value || '').trim();
        if (search) url += '&search=' + encodeURIComponent(search) + '&search_mode=' + (currentSearchMode || 'customers');
        if (labelFilterId) url += '&label_id=' + labelFilterId;
        if (assignedToMe)  url += '&assigned_to_me=1';
        apiFetch(url).then(d => {
            if (!d.success) return;
            renderConversations(d.conversations, { searchMode: currentSearchMode, searchTerm: search });
        });
    };

    // ── Init ──
    loadQurbaniSettings();
    loadConversations();
    convPollTimer = setInterval(() => {
        loadConversations();
    }, POLL_INTERVAL);
    // Phase 2 — keep the @me pip in sync with the server's mention count.
    refreshMentionsPip();
    setInterval(refreshMentionsPip, 30000);
})();
</script>
@endverbatim
@endpush
