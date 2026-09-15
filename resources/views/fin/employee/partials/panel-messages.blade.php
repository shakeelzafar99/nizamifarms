{{-- ══════════ Daily Closing · RIGHT PANE BODY · Messages ═════════════════
     Payment follow-ups: chase / proof-in / settled, over a 3-day window.

     Sep-2026: lifted OUT of outstanding-invoices.blade.php unchanged, so the
     page and `EmployeeCashController::dailyClosingPanels()` (the in-place
     background refresh) render from ONE source and can never drift. The move
     was byte-verified: the page rendered identically before and after.

     Needs: $onlineFollowUp, $canApproveL1, $canWaChat
     ⚠ The group ids (followup-body / -older / -messaged / -proof / -l1done) are
     load-bearing: the refresh remembers which groups were open and restores them
     after a swap, and dcMarkRowApproved() moves a row between them by id. --}}
    <!-- 💰 Payment Follow-ups (Aug-2026) — replaces the old today-only "Online
         WhatsApp Messages" panel. Three tiers: chase / proof-in / settled, held
         for a 3-day window. Built by OnlineFollowUpService. -->
    @if(isset($onlineFollowUp) && $onlineFollowUp)
    @php
        $fuNeedsAction = $onlineFollowUp['chase_count'] > 0;
        // The panel's own colour reports its state: red while anything needs a
        // message, green once the chase list is clear.
        $fuHeadGradient = $fuNeedsAction
            ? 'linear-gradient(to right, #e11d48, #be123c)'
            : 'linear-gradient(to right, #059669, #047857)';
        $fuBodyBg = $fuNeedsAction
            ? 'linear-gradient(to right, #fff1f2, #ffe4e6)'
            : 'linear-gradient(to right, #f0fdf4, #dcfce7)';
        $fuBorder = $fuNeedsAction ? '#fda4af' : '#86efac';
    @endphp
    <div class="dc-panel">
        <div style="background: {{ $fuBodyBg }}; border: 2px solid {{ $fuBorder }};" class="rounded-lg shadow-sm overflow-hidden">
            <!-- Header -->
            <div style="background: {{ $fuHeadGradient }};" class="px-4 py-3 flex flex-wrap items-center justify-between gap-2 cursor-pointer" onclick="document.getElementById('followup-body').classList.toggle('hidden')">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-lg">💰</span>
                    <h3 class="text-sm font-bold text-white">Payment Follow-ups</h3>
                    <span class="text-xs text-white opacity-75">online deliveries · last {{ $onlineFollowUp['window_days'] }} days</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($onlineFollowUp['chase_count'] > 0)
                    <span class="animate-pulse text-xs bg-white text-red-700 px-2 py-0.5 rounded-full font-bold">{{ $onlineFollowUp['chase_count'] }} to chase</span>
                    @endif
                    @if($onlineFollowUp['new_customer_count'] > 0)
                    <span class="text-xs bg-amber-300 text-amber-900 px-2 py-0.5 rounded-full font-bold" style="background-color:#fcd34d; color:#78350f;">⚠ {{ $onlineFollowUp['new_customer_count'] }} new</span>
                    @endif
                    {{-- Same number as the pane badge and the review group — see the
                         data-fu-proof note there. --}}
                    @if(($onlineFollowUp['proof_review_count'] ?? $onlineFollowUp['proof_in_count']) > 0)
                    <span data-fu-proof class="text-xs px-2 py-0.5 rounded-full font-bold" style="background-color:#fef3c7; color:#92400e;">{{ $onlineFollowUp['proof_review_count'] ?? $onlineFollowUp['proof_in_count'] }} proof in</span>
                    @endif
                    @if($onlineFollowUp['settled_count'] > 0)
                    <span class="text-xs px-2 py-0.5 rounded-full font-bold" style="background-color:#dcfce7; color:#166534;">✅ {{ $onlineFollowUp['settled_count'] }} settled</span>
                    @endif
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
            </div>

            <!-- Body (open whenever something needs chasing) -->
            <div id="followup-body" class="{{ $fuNeedsAction ? '' : 'hidden' }}">

                <!-- ── TIER 1 · CHASE ───────────────────────────────────── -->
                @if($onlineFollowUp['chase_count'] > 0)

                {{-- Open group: new customers (any day) + everything delivered
                     today. Day 1 is when the confirmation-and-bank-details
                     message is worth sending, and a new customer is worth
                     chasing on all three days. --}}
                @if($onlineFollowUp['chase_primary_count'] > 0)
                <div class="px-4 py-2 border-b" style="border-color: {{ $fuBorder }};">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold text-red-700">🔴 Chase now — new customers &amp; delivered today</span>
                        <span class="text-xs font-semibold text-gray-600">{{ $onlineFollowUp['chase_primary_count'] }} · Rs. {{ number_format($onlineFollowUp['chase_primary_amount']) }}</span>
                    </div>
                    @foreach($onlineFollowUp['chase_primary'] as $row)
                        @include('fin.employee.partials.followup-row', ['row' => $row])
                    @endforeach
                </div>
                @endif

                {{-- Collapsed group: established customers from day 2-3. They are
                     already unapproved L1/L2 items in Online Approvals, which has
                     its own invoice-bearing reminder — so this panel shouldn't
                     shout about them a second and third time. One click away,
                     never gone. --}}
                @if($onlineFollowUp['chase_secondary_count'] > 0)
                <div class="border-b" style="border-color: {{ $fuBorder }};">
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-white"
                         onclick="document.getElementById('followup-older').classList.toggle('hidden')">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold text-gray-600">🕓 {{ $onlineFollowUp['chase_secondary_count'] }} older · Rs. {{ number_format($onlineFollowUp['chase_secondary_amount']) }}</span>
                            <span class="text-xs text-gray-500">existing customers from day 2–3 — also chaseable from Online Approvals</span>
                        </div>
                        <span class="text-xs text-gray-400">▸</span>
                    </div>
                    <div id="followup-older" class="hidden px-4 pb-2">
                        @foreach($onlineFollowUp['chase_secondary'] as $row)
                            @include('fin.employee.partials.followup-row', ['row' => $row])
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Collapsed group: already messaged TODAY, by the delivered →
                     payment-confirmation automation or by hand. Their Send button
                     is disabled for the rest of the day anyway, so leaving them in
                     the open group above would fill it with rows that need no
                     action. They come back OPEN tomorrow as day 2, where the
                     button offers the invoice-bearing payment reminder. --}}
                @if(($onlineFollowUp['chase_messaged_count'] ?? 0) > 0)
                <div class="border-b" style="border-color: {{ $fuBorder }};">
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-white"
                         onclick="document.getElementById('followup-messaged').classList.toggle('hidden')">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold text-gray-600">✓ {{ $onlineFollowUp['chase_messaged_count'] }} messaged today · Rs. {{ number_format($onlineFollowUp['chase_messaged_amount']) }}</span>
                            @if(($onlineFollowUp['chase_auto_count'] ?? 0) > 0)
                                <span class="text-xs px-2 py-0.5 rounded-full" style="background:#dcfce7;color:#166534;">🤖 {{ $onlineFollowUp['chase_auto_count'] }} sent automatically</span>
                            @endif
                            <span class="text-xs text-gray-500">nothing to do today — they return tomorrow if still unpaid</span>
                        </div>
                        <span class="text-xs text-gray-400">▸</span>
                    </div>
                    <div id="followup-messaged" class="hidden px-4 pb-2">
                        @foreach($onlineFollowUp['chase_messaged'] as $row)
                            @include('fin.employee.partials.followup-row', ['row' => $row])
                        @endforeach
                    </div>
                </div>
                @endif

                @else
                <div class="px-4 py-3 text-xs font-semibold text-green-700">
                    ✅ Nothing to chase — every online delivery from the last {{ $onlineFollowUp['window_days'] }} days has proof or is settled.
                </div>
                @endif
                {{-- ── TIER 2 · PROOF IN ──────────────────────────────────
                     Aug-2026: this tier used to be one collapsed list captioned
                     "waiting on Online Approvals, no action here". It now splits
                     by what is LEFT TO DO, because the closing manager approves
                     these himself from this screen:

                       review   proof landed, invoice still unapproved — OPEN by
                                default, and the only group with a button.
                       L1 done  already approved at L1, so the money is ALREADY in
                                the balances (BalancePostingService runs at L1 —
                                L2 only verifies). Collapsed, nothing to press,
                                kept visible until Taimur clears it at L2.

                     Without the split an order looked exactly the same before and
                     after it was approved. --}}
                @if(($onlineFollowUp['proof_review_count'] ?? 0) > 0)
                <div class="border-b" style="border-color: {{ $fuBorder }};">
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-white"
                         onclick="document.getElementById('followup-proof').classList.toggle('hidden')">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold" style="color:#92400e;">🟡 {{ $onlineFollowUp['proof_review_count'] }} proof in · Rs. {{ number_format($onlineFollowUp['proof_review_amount']) }}</span>
                            <span class="text-xs text-gray-500">{{ $canApproveL1 ? 'open each proof, then approve' : 'proof received — waiting on Online Approvals' }}</span>
                            @foreach($onlineFollowUp['proof_in_breakdown'] as $b)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full text-white" style="background-color: {{ $b['color'] }};">{{ $b['count'] }} {{ $b['label'] }}</span>
                            @endforeach
                        </div>
                        <span class="text-xs text-gray-400">▾</span>
                    </div>
                    <div id="followup-proof" class="px-4 pb-2">
                        @foreach($onlineFollowUp['proof_review'] as $row)
                        @php $proof = $row['payment_proof'] ?? null; @endphp
                        <div class="flex items-center justify-between py-1.5 px-3 mb-1 rounded" style="background-color: #fffdf5;">
                            <div class="flex items-center gap-2 flex-1 min-w-0 flex-wrap">
                                <span class="text-xs text-gray-400">Day {{ $row['day_number'] }}</span>
                                <span class="text-xs font-mono font-bold text-gray-700">{{ $row['order_number'] }}</span>
                                <span class="text-xs text-gray-600 truncate">{{ $row['customer_name'] }}</span>
                                @if($row['is_new_customer'])
                                <span class="text-xs font-bold px-1.5 py-0.5 rounded" style="background-color:#fef3c7; color:#92400e;">new</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-gray-800">Rs. {{ number_format($row['amount']) }}</span>
                                @if($canWaChat && !empty($row['customer_phone']))
                                <button type="button" class="dc-chat-btn"
                                        onclick="event.stopPropagation(); openWaChatDrawer(@js($row['customer_phone']), @js($row['customer_name']))"
                                        title="Read this customer's WhatsApp chat">💬</button>
                                @endif
                                @if($proof && ($proof['status'] ?? 'none') !== 'none')
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full text-white whitespace-nowrap"
                                      style="background-color: {{ $proof['color'] }}; cursor: pointer;"
                                      onclick="event.stopPropagation(); dcOpenProof(this)"
                                      data-proof="{{ json_encode(['orderId' => $row['id'], 'orderNumber' => $row['order_number'], 'ledgerId' => $row['ledger_id'] ?? null, 'amount' => $row['amount'], 'customerName' => $row['customer_name'], 'canApprove' => (bool) ($row['can_approve'] ?? false)]) }}"
                                      title="{{ $proof['label'] }} — open the proof{{ ($canApproveL1 && !empty($row['can_approve'])) ? ' and approve it' : '' }}">
                                    {{ $proof['has_whatsapp'] ? '📷' : '' }}{{ !empty($proof['has_sms']) ? '📱' : '' }}{{ $proof['has_email'] ? '✉️' : '' }} {{ $proof['label'] }}
                                    {{ ($canApproveL1 && !empty($row['can_approve'])) ? '🔍 review' : '🔍' }}
                                </span>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- L1 DONE — approved here (or in Approvals) but not yet verified
                     at L2. The money is already counted; this group exists so the
                     manager can see what he has done today, and so an approved
                     order stops looking identical to one nobody has touched. --}}
                @if(($onlineFollowUp['proof_l1_done_count'] ?? 0) > 0)
                <div class="border-b" style="border-color: {{ $fuBorder }};">
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-white"
                         onclick="document.getElementById('followup-l1done').classList.toggle('hidden')">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold" style="color:#1d4ed8;">☑ {{ $onlineFollowUp['proof_l1_done_count'] }} approved · Rs. {{ number_format($onlineFollowUp['proof_l1_done_amount']) }}</span>
                            <span class="text-xs text-gray-500">already in the balances — waiting on Level 2 verification</span>
                        </div>
                        <span class="text-xs text-gray-400">▸</span>
                    </div>
                    <div id="followup-l1done" class="hidden px-4 pb-2">
                        @foreach($onlineFollowUp['proof_l1_done'] as $row)
                        @php $proof = $row['payment_proof'] ?? null; @endphp
                        <div class="flex items-center justify-between py-1.5 px-3 mb-1 rounded" style="background-color: #f0f7ff;">
                            <div class="flex items-center gap-2 flex-1 min-w-0 flex-wrap">
                                <span class="text-xs text-gray-400">Day {{ $row['day_number'] }}</span>
                                <span class="text-xs font-mono font-bold text-gray-700">{{ $row['order_number'] }}</span>
                                <span class="text-xs text-gray-600 truncate">{{ $row['customer_name'] }}</span>
                                <span class="text-xs font-bold px-1.5 py-0.5 rounded" style="background-color:#dbeafe; color:#1e40af;">L1 done</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-gray-800">Rs. {{ number_format($row['amount']) }}</span>
                                @if($canWaChat && !empty($row['customer_phone']))
                                <button type="button" class="dc-chat-btn"
                                        onclick="event.stopPropagation(); openWaChatDrawer(@js($row['customer_phone']), @js($row['customer_name']))"
                                        title="Read this customer's WhatsApp chat">💬</button>
                                @endif
                                @if($proof && ($proof['status'] ?? 'none') !== 'none')
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full text-white whitespace-nowrap"
                                      style="background-color: {{ $proof['color'] }}; cursor: pointer;"
                                      onclick="event.stopPropagation(); dcOpenProof(this)"
                                      data-proof="{{ json_encode(['orderId' => $row['id'], 'orderNumber' => $row['order_number'], 'ledgerId' => null, 'amount' => $row['amount'], 'customerName' => $row['customer_name'], 'canApprove' => false]) }}"
                                      title="{{ $proof['label'] }} — view the proof">
                                    {{ $proof['has_whatsapp'] ? '📷' : '' }}{{ !empty($proof['has_sms']) ? '📱' : '' }}{{ $proof['has_email'] ? '✉️' : '' }} {{ $proof['label'] }} 🔍
                                </span>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- ── TIER 3 · SETTLED (count only) ─────────────────────── -->
                @if($onlineFollowUp['settled_count'] > 0)
                <div class="px-4 py-2 text-xs font-semibold" style="color:#166534;">
                    ✅ {{ $onlineFollowUp['settled_count'] }} settled · Rs. {{ number_format($onlineFollowUp['settled_amount']) }} — approved in the ledger, nothing to do.
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif
                @if(empty($onlineFollowUp))
                <div class="dc-pane-empty">No online deliveries in the follow-up window.</div>
                @endif
