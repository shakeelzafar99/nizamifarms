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
                <input type="text" class="wa-search" id="waSearch" placeholder="Search conversations..." />
            </div>
            <div class="wa-filters">
                <button class="wa-filter-btn active" data-filter="all">All</button>
                <button class="wa-filter-btn" data-filter="unread">Unread</button>
            </div>
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
        </div>
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
                <h4>Add New Template</h4>
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
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;background:#fffbeb;padding:2px 6px;border-radius:4px;border:1px solid #fde68a;margin-top:4px;"><input type="checkbox" id="tplShowInvoice" /> 📄 Use for Invoices</label>
                    </div>
                </div>
                <button onclick="saveNewTemplate()" class="wa-mgr-save">Save Template</button>
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
    let searchTimeout = null;
    let convPollTimer = null;
    let msgPollTimer = null;
    let templates = [];
    var _cachedApiTemplates = null;

    function apiFetch(url, opts = {}) {
        opts.headers = { ...(opts.headers || {}), 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' };
        return fetch(url, opts).then(r => r.json());
    }

    function fmtTime(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const diff = Math.floor((now - d) / 86400000);
        if (diff === 0) return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        if (diff === 1) return 'Yesterday';
        if (diff < 7) return d.toLocaleDateString([], {weekday:'short'});
        return d.toLocaleDateString([], {day:'numeric',month:'short'});
    }
    function fmtMsgTime(iso) {
        if (!iso) return '';
        return new Date(iso).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    }
    function statusIcon(s) {
        return {sent:'✓',delivered:'✓✓',read:'✓✓',failed:'✕'}[s] || '';
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    // ── Conversations ──
    function loadConversations() {
        const search = document.getElementById('waSearch').value.trim();
        let url = '/messages/conversations?filter=' + currentFilter;
        if (search) url += '&search=' + encodeURIComponent(search);
        apiFetch(url).then(d => {
            if (!d.success) return;
            renderConversations(d.conversations);
        });
    }

    function renderConversations(convs) {
        const el = document.getElementById('waConvList');
        if (!convs.length) {
            el.innerHTML = '<div class="wa-loading">No conversations found</div>';
            return;
        }
        el.innerHTML = convs.map(c => {
            const isActive = c.id === activeConvId;
            const isUnread = c.unread_count > 0;
            let cls = 'wa-conv-item';
            if (isActive) cls += ' active';
            if (isUnread) cls += ' unread';
            return `<div class="${cls}" onclick="openConv(${c.id})" data-id="${c.id}">
                <div class="wa-avatar">${(c.customer_name||'?')[0].toUpperCase()}</div>
                <div class="wa-conv-info">
                    <div class="wa-conv-top">
                        <div class="wa-conv-name">${esc(c.customer_name || c.wa_phone)}</div>
                        <div class="wa-conv-time">${fmtTime(c.last_message_at)}</div>
                    </div>
                    <div class="wa-conv-bottom">
                        <div class="wa-conv-preview">${c.last_message_direction==='outbound'?'✓ ':''}${esc(c.last_message_preview||'No messages yet')}</div>
                        ${isUnread ? `<div class="wa-unread-badge">${c.unread_count}</div>` : ''}
                    </div>
                    ${c.customer_city ? `<div class="wa-conv-city">${esc(c.customer_city)}</div>` : ''}
                </div>
            </div>`;
        }).join('');
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
            document.getElementById('waChatName').textContent = name;
            document.getElementById('waChatAvatar').textContent = (name || '?')[0].toUpperCase();
            let sub = d.conversation.wa_phone;
            if (d.conversation.customer_city) sub += ' · ' + d.conversation.customer_city;
            if (d.conversation.customer_orders) sub += ' · ' + d.conversation.customer_orders + ' orders';
            document.getElementById('waChatSub').textContent = sub;

            const sessionActive = d.conversation.session_active;
            document.getElementById('waSessionBadge').style.display = sessionActive ? 'none' : 'block';
            document.getElementById('waSessionExpiredBar').style.display = sessionActive ? 'none' : 'block';
            document.getElementById('waActiveSessionInput').style.display = sessionActive ? 'block' : 'none';

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
                const sessionActive = d.conversation.session_active;
                document.getElementById('waSessionBadge').style.display = sessionActive ? 'none' : 'block';
                document.getElementById('waSessionExpiredBar').style.display = sessionActive ? 'none' : 'block';
                document.getElementById('waActiveSessionInput').style.display = sessionActive ? 'block' : 'none';
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
        msgs.forEach(m => {
            const isOut = m.direction === 'outbound';
            const meta = (typeof m.metadata === 'string') ? JSON.parse(m.metadata || '{}') : (m.metadata || {});
            html += `<div class="wa-msg ${isOut?'out':'in'}">`;
            if (m.type === 'template') html += `<div class="wa-msg-tpl-badge">Template: ${esc(m.template_name||'')}</div>`;
            if (m.type === 'image' && m.media_url) {
                html += `<div class="wa-msg-image"><a href="${esc(m.media_url)}" target="_blank"><img src="${esc(m.media_url)}" alt="Image" style="max-width:260px;max-height:260px;border-radius:8px;display:block;cursor:pointer;" /></a></div>`;
            }
            if (m.type === 'audio') html += '<div class="wa-msg-media">🎤 Voice Note</div>';
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
            if (m.content && m.type !== 'location') {
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
                    if (r.success) renderMessages(r.messages, r.has_more);
                });
            } else if (d.session_expired) {
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
    window.openTemplatePicker = function() {
        document.getElementById('waTemplateModal').style.display = 'flex';
        document.getElementById('waTemplateList').innerHTML = '<div class="wa-loading">Loading...</div>';
        apiFetch('/messages/templates').then(d => {
            if (!d.success) return;
            templates = d.templates || [];
            renderTemplates();
        });
    };
    window.closeTemplatePicker = function() {
        document.getElementById('waTemplateModal').style.display = 'none';
    };

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
                    const defaultVal = (i === 1 && activeConv) ? esc(activeConv.customer_name || '') : '';
                    html += `<input class="wa-tpl-param-in" data-tpl="${idx}" data-var="${i}" placeholder="Variable {{${i}}}" value="${defaultVal}" />`;
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
            const val = document.querySelector(`input[data-tpl="${idx}"][data-var="${i}"]`)?.value?.trim() || '';
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
                document.getElementById('waSessionBadge').style.display = 'block';
                document.getElementById('waSessionExpiredBar').style.display = 'block';
                document.getElementById('waActiveSessionInput').style.display = 'none';
            }
        });
    };

    // ── Template Manager ──
    window.openTemplateManager = function() {
        document.getElementById('waTemplateManager').style.display = 'flex';
        loadExistingTemplates();
    };
    window.closeTemplateManager = function() {
        document.getElementById('waTemplateManager').style.display = 'none';
    };

    document.getElementById('tplHasButtons')?.addEventListener('change', function() {
        document.getElementById('tplButtonLabelsDiv').style.display = this.value === '1' ? 'block' : 'none';
    });

    function loadExistingTemplates() {
        apiFetch('/messages/templates').then(d => {
            const el = document.getElementById('waExistingTemplates');
            const tpls = d.templates || [];
            if (!tpls.length) { el.innerHTML = '<p style="color:#9ca3af;font-size:13px;">No templates added yet.</p>'; return; }
            el.innerHTML = tpls.map(t => {
                const si = (t.show_in || 'messages,orders,customers').split(',');
                const tagStyle = 'display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:500;margin-right:3px;';
                const tags = [
                    {key:'messages',label:'Messages',bg:'#dbeafe',fg:'#1e40af'},
                    {key:'orders',label:'Orders',bg:'#fef3c7',fg:'#92400e'},
                    {key:'customers',label:'Customers',bg:'#d1fae5',fg:'#065f46'},
                    {key:'shopify',label:'Shopify',bg:'#ede9fe',fg:'#5b21b6'},
                    {key:'invoice',label:'📄 Invoice',bg:'#fff7ed',fg:'#9a3412'}
                ].filter(x => si.includes(x.key)).map(x => `<span style="${tagStyle}background:${x.bg};color:${x.fg};">${x.label}</span>`).join('');
                return `<div class="wa-mgr-item" style="flex-wrap:wrap;">
                    <div style="flex:1;">
                        <div class="wa-mgr-item-name">${esc(t.display_name || t.name)}</div>
                        <div class="wa-mgr-item-meta">${esc(t.name)} · ${t.variable_count} vars · ${t.status}</div>
                        <div style="margin-top:4px;">${tags}</div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <button onclick="editTemplateVisibility(${t.id}, '${esc(t.show_in || 'messages,orders,customers')}')" style="padding:4px 10px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;font-size:11px;cursor:pointer;">Edit</button>
                        <button onclick="deleteTemplate(${t.id})" class="wa-mgr-del">Delete</button>
                    </div>
                </div>`;
            }).join('');
        });
    }

    function getShowInValue() {
        const parts = [];
        if (document.getElementById('tplShowMessages').checked) parts.push('messages');
        if (document.getElementById('tplShowOrders').checked) parts.push('orders');
        if (document.getElementById('tplShowCustomers').checked) parts.push('customers');
        if (document.getElementById('tplShowShopify').checked) parts.push('shopify');
        if (document.getElementById('tplShowInvoice').checked) parts.push('invoice');
        return parts.length ? parts.join(',') : 'messages';
    }

    window.saveNewTemplate = function() {
        const name = document.getElementById('tplName').value.trim();
        const displayName = document.getElementById('tplDisplayName').value.trim();
        const body = document.getElementById('tplBody').value.trim();
        if (!name || !displayName || !body) { alert('Please fill in Template Name, Display Name, and Body Text.'); return; }

        const hasButtons = document.getElementById('tplHasButtons').value === '1';
        const buttonLabels = hasButtons ? document.getElementById('tplButtonLabels').value.split(',').map(s => s.trim()).filter(Boolean) : [];

        apiFetch('/messages/templates', {
            method: 'POST',
            body: JSON.stringify({
                name: name,
                display_name: displayName,
                body_text: body,
                category: document.getElementById('tplCategory').value,
                variable_count: parseInt(document.getElementById('tplVarCount').value) || 0,
                has_buttons: hasButtons ? 1 : 0,
                button_labels: buttonLabels,
                header_text: document.getElementById('tplHeader').value.trim(),
                footer_text: document.getElementById('tplFooter').value.trim(),
                show_in: getShowInValue()
            })
        }).then(d => {
            if (d.success) {
                ['tplName','tplDisplayName','tplBody','tplHeader','tplFooter','tplButtonLabels'].forEach(id => { var el = document.getElementById(id); if(el) el.value = ''; });
                document.getElementById('tplVarCount').value = '0';
                document.getElementById('tplHasButtons').value = '0';
                document.getElementById('tplButtonLabelsDiv').style.display = 'none';
                document.getElementById('tplShowMessages').checked = true;
                document.getElementById('tplShowOrders').checked = true;
                document.getElementById('tplShowCustomers').checked = true;
                document.getElementById('tplShowShopify').checked = true;
                document.getElementById('tplShowInvoice').checked = false;
                _cachedApiTemplates = null;
                loadExistingTemplates();
            } else {
                alert(d.message || 'Failed to save template');
            }
        }).catch(() => alert('Failed to save template'));
    };

    window.editTemplateVisibility = function(id, currentShowIn) {
        const si = currentShowIn.split(',');
        const m = si.includes('messages'), o = si.includes('orders'), c = si.includes('customers'), s = si.includes('shopify'), inv = si.includes('invoice');
        const html = `<div style="padding:16px;">
            <p style="font-size:14px;font-weight:600;margin:0 0 12px;">Where should this template appear?</p>
            <label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;cursor:pointer;"><input type="checkbox" id="editShowMsg" ${m?'checked':''}/> Messages</label>
            <label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;cursor:pointer;"><input type="checkbox" id="editShowOrd" ${o?'checked':''}/> Open Orders</label>
            <label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;cursor:pointer;"><input type="checkbox" id="editShowCust" ${c?'checked':''}/> Customers</label>
            <label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;cursor:pointer;"><input type="checkbox" id="editShowShop" ${s?'checked':''}/> Shopify</label>
            <label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;cursor:pointer;background:#fffbeb;padding:4px 6px;border-radius:4px;border:1px solid #fde68a;"><input type="checkbox" id="editShowInv" ${inv?'checked':''}/> 📄 Use for Invoices <span style="font-size:10px;color:#92400e;">(auto-fills template name)</span></label>
            <div style="display:flex;gap:8px;margin-top:12px;">
                <button onclick="saveTemplateVisibility(${id})" style="padding:6px 16px;background:#16A34A;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Save</button>
                <button onclick="document.getElementById('editVisDialog').remove()" style="padding:6px 16px;background:#e5e7eb;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Cancel</button>
            </div>
        </div>`;
        let dialog = document.getElementById('editVisDialog');
        if (dialog) dialog.remove();
        dialog = document.createElement('div');
        dialog.id = 'editVisDialog';
        dialog.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;box-shadow:0 25px 50px rgba(0,0,0,0.25);z-index:10100;min-width:260px;';
        dialog.innerHTML = html;
        document.body.appendChild(dialog);
    };

    window.saveTemplateVisibility = function(id) {
        const parts = [];
        if (document.getElementById('editShowMsg').checked) parts.push('messages');
        if (document.getElementById('editShowOrd').checked) parts.push('orders');
        if (document.getElementById('editShowCust').checked) parts.push('customers');
        if (document.getElementById('editShowShop').checked) parts.push('shopify');
        if (document.getElementById('editShowInv').checked) parts.push('invoice');
        const showIn = parts.length ? parts.join(',') : 'messages';
        apiFetch('/messages/templates/' + id, {
            method: 'PUT',
            body: JSON.stringify({show_in: showIn})
        }).then(d => {
            document.getElementById('editVisDialog')?.remove();
            if (d.success) { _cachedApiTemplates = null; loadExistingTemplates(); }
            else alert(d.message || 'Failed to update');
        }).catch(() => alert('Failed to update'));
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
                    <img id="waInvPickerPreviewImg" style="max-width:100%;max-height:250px;border-radius:4px;" />
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button id="waInvPickerPrevBtn" onclick="previewInvPicker(${orderId})" style="flex:1;padding:9px;border:1px solid #d97706;color:#d97706;background:#fff;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Preview</button>
                <button id="waInvPickerSendBtn" onclick="sendInvPicker(${orderId})" style="flex:1;padding:9px;background:#25D366;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;" disabled>Send Invoice</button>
            </div>
            <div id="waInvPickerStatus" style="margin-top:8px;font-size:13px;text-align:center;display:none;"></div>`;

        apiFetch('/messages/templates?context=invoice').then(d => {
            if (d.success && d.templates && d.templates.length) {
                const el = document.getElementById('waInvTplName');
                if (el && !el.value) el.value = d.templates[0].name;
            }
        }).catch(() => {});
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

        apiFetch('/messages/send-invoice', {
            method: 'POST',
            body: JSON.stringify({ order_id: orderId, phone: phone, template_name: tplName, body_params: bodyParams, conversation_id: activeConvId })
        }).then(d => {
            if (d.success) {
                status.style.display = 'block'; status.style.color = '#16a34a'; status.textContent = 'Invoice sent!';
                btn.textContent = 'Sent!';
                setTimeout(() => {
                    document.getElementById('waInvoicePickerModal')?.remove();
                    if (activeConvId) apiFetch('/messages/conversations/' + activeConvId).then(r => { if (r.success) renderMessages(r.messages, r.has_more); });
                    loadConversations();
                }, 1500);
            } else { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = d.message || 'Failed'; btn.textContent = 'Send Invoice'; btn.disabled = false; }
        }).catch(e => { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = e.message; btn.textContent = 'Send Invoice'; btn.disabled = false; });
    };

    // ── Init ──
    loadConversations();
    convPollTimer = setInterval(() => {
        loadConversations();
    }, POLL_INTERVAL);
})();
</script>
@endverbatim
@endpush
