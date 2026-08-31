{{-- One chase row. Shared by the open (new + delivered-today) group and the
     collapsed day-2/3 group so the two can never render differently. --}}
                    @php
                        $proof = $row['payment_proof'] ?? null;
                        $proofLabel = ($proof && ($proof['status'] ?? 'none') !== 'none') ? $proof['label'] : '';
                        // Everything the send flow needs, handed over as one JSON
                        // blob on a data attribute. Blade escapes it, so an
                        // apostrophe in a customer name can no longer break the
                        // JS the way the old 8-positional-argument onclick did.
                        $payload = [
                            'id'            => $row['id'],
                            'order_number'  => $row['order_number'],
                            'customer_name' => $row['customer_name'],
                            'customer_phone'=> $row['customer_phone'],
                            'rider_name'    => $row['rider_name'],
                            'delivery_date' => $row['delivery_date'],
                            'delivery_time' => $row['delivery_time'],
                            'amount'        => $row['amount'],
                            'template'      => $row['template'],
                            'day_number'    => $row['day_number'],
                            'proof_label'   => $proofLabel,
                        ];
                        $dayColors = [1 => ['#f1f5f9', '#475569'], 2 => ['#fef3c7', '#92400e'], 3 => ['#fee2e2', '#991b1b']];
                        [$dayBg, $dayFg] = $dayColors[$row['day_number']] ?? $dayColors[3];
                        $rowBg = $row['is_new_customer'] ? '#fffbeb' : ($row['reminded_today'] ? '#f8fafc' : '#fef2f2');
                    @endphp
                    <div id="fu-row-{{ $row['id'] }}" class="flex items-center justify-between py-2 px-3 mb-1.5 rounded-lg"
                         style="background-color: {{ $rowBg }};{{ $row['is_new_customer'] ? ' border-left: 4px solid #f59e0b;' : '' }}">
                        <div class="flex items-center gap-2 flex-1 min-w-0 flex-wrap">
                            @if($row['is_new_customer'])
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full whitespace-nowrap" style="background-color:#f59e0b; color:#fff;"
                                  title="Only {{ $row['lifetime_orders'] }} delivered order(s) — needs immediate follow-up">
                                ⚠ NEW · {{ $row['customer_ordinal'] }}
                            </span>
                            @endif
                            <span class="text-xs font-bold whitespace-nowrap px-1.5 py-0.5 rounded" style="background-color: {{ $dayBg }}; color: {{ $dayFg }};"
                                  title="Delivered {{ $row['delivery_date'] }} at {{ $row['delivery_time'] }}">Day {{ $row['day_number'] }}</span>
                            <span class="text-xs font-mono font-bold text-gray-700">{{ $row['order_number'] }}</span>
                            <span class="text-xs text-gray-700 truncate">{{ $row['customer_name'] }}</span>
                            <span class="text-xs text-gray-400">{{ $row['rider_name'] }}</span>
                            <span class="text-xs font-semibold text-gray-800">Rs. {{ number_format($row['amount']) }}</span>
                            @if($row['reminded_label'])
                            <span class="text-xs text-gray-500 italic">{{ $row['reminded_label'] }}</span>
                            @endif
                            @if($row['is_last_day'])
                            <span class="text-xs font-semibold" style="color:#b91c1c;" title="Last day in this list — after today it moves to Online Approvals">last day</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 ml-3">
                            <span class="fu-status text-xs text-gray-500"></span>
                            {{-- Read the thread before chasing. A customer who
                                 replied "paid, screenshot attached" ten minutes
                                 ago looked identical here to one who never
                                 answered — the reminder went out either way.
                                 Opens the real Messages chat in a drawer on this
                                 page (partials/wa-chat-drawer). --}}
                            @if(($canWaChat ?? false) && !empty($row['customer_phone']))
                            <button type="button" class="dc-chat-btn"
                                    onclick="event.stopPropagation(); openWaChatDrawer(@js($row['customer_phone']), @js($row['customer_name']))"
                                    title="Read {{ $row['customer_name'] }}'s WhatsApp chat before sending">💬</button>
                            @endif
                            <button type="button"
                                data-row="{{ json_encode($payload) }}"
                                onclick="sendFollowUp(this)"
                                @if($row['reminded_today']) disabled title="Already reminded today — try again tomorrow" @endif
                                style="background-color: {{ $row['reminded_today'] ? '#cbd5e1' : ($proofLabel ? '#f59e0b' : '#25D366') }}; min-width: 132px;"
                                class="text-sm hover:opacity-90 text-white px-3 py-1.5 rounded-lg font-bold transition-all cursor-pointer whitespace-nowrap flex items-center justify-center gap-1.5 shadow-sm">
                                {{ $row['reminded_today'] ? '✓ Reminded today' : '📱 ' . $row['button_label'] }}
                            </button>
                        </div>
                    </div>
