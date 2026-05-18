@extends('layouts.app')

@section('title', 'Qurbani Settings')

@push('custom_css')
<style>
.qurbani-settings-container { background: #f8fafc; min-height: 100vh; }
.settings-header { background: linear-gradient(135deg, #92400e, #b45309); color: white; padding: 24px 32px; border-radius: 12px; margin-bottom: 24px; }
.settings-header h1 { font-size: 24px; font-weight: 700; margin: 0; }
.settings-header p { font-size: 14px; opacity: 0.85; margin: 6px 0 0; }
.field-section { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; }
.field-header { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.field-title { font-size: 16px; font-weight: 700; color: #111; }
.field-subtitle { font-size: 12px; color: #6b7280; }
.field-body { padding: 16px 20px; }
.option-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 8px; transition: background 0.15s; }
.option-row:hover { background: #f3f4f6; }
.option-value { flex: 1; font-size: 14px; font-weight: 500; color: #374151; }
.option-order { font-size: 12px; color: #9ca3af; min-width: 24px; text-align: center; }
.option-actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
.btn-edit { background: #eff6ff; color: #2563eb; }
.btn-edit:hover { background: #dbeafe; }
.btn-delete { background: #fef2f2; color: #dc2626; }
.btn-delete:hover { background: #fee2e2; }
.btn-add { background: #b45309; color: white; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
.btn-add:hover { background: #92400e; }
.add-form { display: flex; gap: 8px; margin-top: 12px; }
.add-input { flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
.add-input:focus { outline: none; border-color: #b45309; box-shadow: 0 0 0 3px rgba(180,83,9,0.1); }
.empty-state { text-align: center; padding: 24px; color: #9ca3af; font-size: 14px; }
.inactive-row { opacity: 0.5; }
.btn-restore { background: #d1fae5; color: #065f46; }
.btn-restore:hover { background: #a7f3d0; }
.toast { position: fixed; top: 20px; right: 20px; z-index: 10000; padding: 12px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: toastIn 0.3s ease; }
.toast-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.toast-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
@keyframes toastIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
</style>
@endpush

@section('content')
<div class="qurbani-settings-container" style="padding: 24px;">
    <div class="settings-header">
        <h1>🐄 Qurbani Settings</h1>
        <p>Manage dropdown values for qurbani order fields. Changes are reflected immediately on the mobile app.</p>
    </div>

    @php
        $riderDeliveredEnabled = \App\Models\FIN\ConfigModel::get('qurbani_rider_delivered_enabled', '0') === '1';
        $qurbaniShippingPrice = \App\Models\FIN\ConfigModel::get('qurbani_shipping_price', '1000');
        $deleteEnabled = \App\Models\FIN\ConfigModel::get('qurbani_delete_enabled', '0') === '1';
        $cancellationCode = \App\Models\FIN\ConfigModel::get('qurbani_cancellation_code', '');
        $defaultPaymentMethod = \App\Models\FIN\ConfigModel::get('qurbani_default_payment_method', 'cash');
        if (!in_array($defaultPaymentMethod, ['cash','online'], true)) { $defaultPaymentMethod = 'cash'; }
        $hiddenCategoriesJson = \App\Models\FIN\ConfigModel::get('qurbani_hidden_stats_categories', '[]');
        $hiddenCategories = json_decode($hiddenCategoriesJson, true) ?: [];
        // Qurbani Operations Base — origin fallback + return-to-base
        // ETA. Stored as 3 ConfigModel keys; empty values mean "fall
        // back to the regular office location chain".
        $qBaseName = (string) \App\Models\FIN\ConfigModel::get('qurbani_base_name', '');
        $qBaseLat  = (string) \App\Models\FIN\ConfigModel::get('qurbani_base_lat',  '');
        $qBaseLng  = (string) \App\Models\FIN\ConfigModel::get('qurbani_base_lng',  '');
        $qBaseConfigured = ($qBaseName !== '' && $qBaseLat !== '' && $qBaseLng !== '');
        // Rider-side ETA auto-refresh.
        $etaRefreshEnabled    = \App\Models\FIN\ConfigModel::get('qurbani_eta_refresh_enabled', '1') === '1';
        $etaRefreshMinutes    = (int) \App\Models\FIN\ConfigModel::get('qurbani_eta_refresh_minutes', '3');
        $etaDelayThresholdMin = (int) \App\Models\FIN\ConfigModel::get('qurbani_eta_delay_threshold_minutes', '10');
        if ($etaRefreshMinutes < 1 || $etaRefreshMinutes > 30) { $etaRefreshMinutes = 3; }

        // Phase 3 (May-2026) — Auto WhatsApp operational updates.
        // All keys gated by the master switch (qurbani_wa_auto_enabled)
        // which DEFAULTS OFF — the system never sends real customer
        // messages until an admin explicitly turns it on for the day.
        $waAutoEnabled                = \App\Models\FIN\ConfigModel::get('qurbani_wa_auto_enabled', '0') === '1';
        $waTestPhone                  = (string) \App\Models\FIN\ConfigModel::get('qurbani_wa_test_phone', '');
        $waSendMaxPerMinute           = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_send_max_per_minute', '10');
        $waSlaughterEnabled           = \App\Models\FIN\ConfigModel::get('qurbani_wa_slaughter_enabled', '0') === '1';
        $waSlaughterTemplate          = (string) \App\Models\FIN\ConfigModel::get('qurbani_wa_slaughter_template', '');
        $waSlaughterDelayMin          = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_slaughter_delay_minutes', '30');
        $waOfdEnabled                 = \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_enabled', '0') === '1';
        $waOfdTemplate                = (string) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_template', '');
        $waOfdSelfCollectionTemplate  = (string) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_self_collection_template', '');
        $waOfdRequireDispatched       = \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_require_dispatched', '1') === '1';
        $waOfdTimingMode              = (string) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_timing_mode', 'before_eta_with_buffer');
        if (!in_array($waOfdTimingMode, ['after_status', 'after_dispatch', 'before_eta_with_buffer'], true)) {
            $waOfdTimingMode = 'before_eta_with_buffer';
        }
        $waOfdMinutesAfterStatus      = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_minutes_after_status', '0');
        $waOfdMinutesAfterDispatch    = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_minutes_after_dispatch', '30');
        $waOfdEtaBufferMinutes        = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_eta_buffer_minutes', '15');
        $waOfdMinutesBefore           = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_minutes_before', '15');

        // Pre-fetch approved templates for the dropdowns. Filtering by
        // status='approved' so only Meta-blessed templates show up;
        // show_in matching is loose because admins may include qurbani
        // in a comma-separated list along with other contexts.
        $waApprovedTemplates = \DB::table('t_wa_templates')
            ->where('status', 'approved')
            ->orderBy('display_name')
            ->get(['name', 'display_name', 'category', 'show_in']);
        $allStatsCategories = \DB::table('t_crm_prod_product')
            ->whereRaw("LOWER(COALESCE(attribute_1,'')) = 'qurbani'")
            ->whereNotNull('attribute_2')
            ->where('attribute_2', '<>', '')
            ->distinct()
            ->orderBy('attribute_2')
            ->pluck('attribute_2')
            ->toArray();
    @endphp

    {{-- Delivery Fee Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">💰 Delivery Fee</div>
                <div class="field-subtitle">Default delivery fee for all qurbani orders (web and mobile)</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <label style="font-weight: 600; font-size: 14px; color: #374151; white-space: nowrap;">Rs.</label>
                <input type="number" id="qurbaniShippingPrice" value="{{ $qurbaniShippingPrice }}" min="0" step="1" style="width: 140px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; font-weight: 600;">
                <button onclick="saveShippingPrice()" class="btn-add">Save</button>
                <span id="shippingPriceSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
            </div>
        </div>
    </div>

    {{-- Default Payment Method Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">💳 Default Payment Method</div>
                <div class="field-subtitle">Pre-selected method when a new qurbani order is created (web and mobile). Users can still change it per order.</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div style="display:flex; gap:8px;">
                    <button type="button" id="dpmCashBtn" onclick="setDefaultPaymentMethod('cash')"
                            style="padding:8px 18px; border-radius:8px; font-weight:700; font-size:13px; border:2px solid {{ $defaultPaymentMethod === 'cash' ? '#10b981' : '#d1d5db' }}; background:{{ $defaultPaymentMethod === 'cash' ? '#10b981' : '#f3f4f6' }}; color:{{ $defaultPaymentMethod === 'cash' ? '#fff' : '#374151' }}; cursor:pointer;">
                        Cash
                    </button>
                    <button type="button" id="dpmOnlineBtn" onclick="setDefaultPaymentMethod('online')"
                            style="padding:8px 18px; border-radius:8px; font-weight:700; font-size:13px; border:2px solid {{ $defaultPaymentMethod === 'online' ? '#3b82f6' : '#d1d5db' }}; background:{{ $defaultPaymentMethod === 'online' ? '#3b82f6' : '#f3f4f6' }}; color:{{ $defaultPaymentMethod === 'online' ? '#fff' : '#374151' }}; cursor:pointer;">
                        Online
                    </button>
                </div>
                <span id="dpmSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
            </div>
        </div>
    </div>

    {{-- Qurbani Operations Base (May-2026) ---------------------------
        Origin fallback when the rider's GPS is stale, AND the final
        waypoint for the "Return to base" ETA on the rider screen.
        Stored as 3 ConfigModel keys; "Clear" wipes all three.
    --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">📍 Qurbani Operations Base</div>
                <div class="field-subtitle">Slaughter / packing depot used as the origin when the rider's GPS isn't available, and as the destination for the "Return to base" ETA after the last delivery. Leave blank to fall back to the regular office.</div>
            </div>
            <div>
                @if($qBaseConfigured)
                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; background:#d1fae5; color:#065f46;">CONFIGURED</span>
                @else
                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; background:#f3f4f6; color:#6b7280;">NOT SET</span>
                @endif
            </div>
        </div>
        <div class="field-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div style="grid-column: 1 / span 3;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:4px;">Name</label>
                    <input type="text" id="qBaseName" value="{{ $qBaseName }}" placeholder="e.g. Bahria Phase 8 Depot" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:4px;">Latitude</label>
                    <input type="number" id="qBaseLat" value="{{ $qBaseLat }}" step="any" placeholder="33.6844" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;" oninput="onQBaseInputsChanged()">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:4px;">Longitude</label>
                    <input type="number" id="qBaseLng" value="{{ $qBaseLng }}" step="any" placeholder="73.0479" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;" oninput="onQBaseInputsChanged()">
                </div>
                <div style="display:flex; align-items:flex-end; gap:6px; flex-wrap: wrap;">
                    <button type="button" onclick="useMyLocationForQBase()" class="btn-edit btn-sm" style="padding:8px 12px; height: 38px;">📍 Use my location</button>
                </div>
            </div>

            {{-- Interactive map preview / picker. Click anywhere on the
                 map or drag the pin to update the lat/lng inputs. The
                 map auto-loads Google Maps JS only when the user clicks
                 "Show map" so the settings page stays fast. --}}
            <div style="margin-top: 14px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom: 8px;">
                    <button type="button" id="qBaseMapToggle" onclick="toggleQBaseMap()" class="btn-edit btn-sm" style="padding:6px 12px;">🗺️ Show map</button>
                    <span style="font-size: 12px; color:#6b7280;">Click on the map or drag the pin to set the depot location.</span>
                </div>
                <div id="qBaseMapWrap" style="display:none; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb;">
                    <div id="qBaseMap" style="width: 100%; height: 320px; background: #f3f4f6;"></div>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:10px; margin-top:14px; flex-wrap: wrap;">
                <button onclick="saveQBaseLocation()" class="btn-add">Save Base</button>
                <button onclick="clearQBaseLocation()" class="btn-delete btn-sm" style="padding:8px 14px;">Clear</button>
                <span id="qBaseSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
                @if($qBaseConfigured)
                    <a href="https://www.google.com/maps/search/?api=1&query={{ $qBaseLat }},{{ $qBaseLng }}" target="_blank" rel="noopener" style="font-size:12px; font-weight:600; color:#1e40af; text-decoration:underline;">Open in Google Maps ↗</a>
                @endif
            </div>
        </div>
    </div>

    {{-- Live ETA tracking + delay detection (May-2026 revision) -------
        Replaces the old "always-on auto-refresh" model with two
        complementary mechanisms:

          1. Delay detection (automatic) — server compares each
             delivery's actual time against the bundle's stored ETA.
             If the slip is more than the threshold, the remaining
             undelivered bundles in that dispatch are recomputed.
             Also runs passively on planner/summary polls so a rider
             running late before any delivery completes is caught.

          2. Live ETA tracking (rider opt-in, per dispatch) — the
             rider can flip a switch on their delivery screen to
             have the app silently recompute ETAs every N minutes
             from their GPS. Defaults OFF; resets each new dispatch.

        The master switch below gates BOTH mechanisms. With it OFF
        Qurbani ETAs are calculated only at dispatch time and on
        manual refresh. The 1/min/rider rate limit applies regardless.
    --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🔄 Live ETA tracking &amp; delay detection</div>
                <div class="field-subtitle">Master switch for Qurbani ETA recomputes. <b>Delay detection</b> auto-recomputes the rest of a dispatch when a delivery slips past its ETA by more than the threshold. <b>Live tracking</b> is opt-in per dispatch from the rider's screen.</div>
            </div>
            <div>
                <span id="etaRefreshBadge" style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; {{ $etaRefreshEnabled ? 'background:#d1fae5; color:#065f46;' : 'background:#f3f4f6; color:#6b7280;' }}">
                    {{ $etaRefreshEnabled ? 'ENABLED' : 'DISABLED' }}
                </span>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; align-items: center; gap: 16px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; flex-wrap: wrap;">
                <button id="etaRefreshBtn" onclick="toggleEtaRefresh()" style="padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; {{ $etaRefreshEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#d1fae5; color:#065f46;' }}">
                    {{ $etaRefreshEnabled ? 'Disable' : 'Enable' }}
                </button>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap: wrap;">
                    <span style="font-weight:700; font-size:13px; color:#92400e; background:#fef3c7; padding:3px 9px; border-radius:6px;">Delay threshold</span>
                    <input type="number" id="etaDelayThresholdMin" value="{{ $etaDelayThresholdMin }}" min="1" max="60" step="1" style="width: 80px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 700; text-align: center;">
                    <label style="font-weight: 600; font-size: 13px; color: #374151;">min past ETA</label>
                    <button onclick="saveEtaDelayThreshold()" class="btn-add" style="padding:6px 12px; font-size:12px;">Save</button>
                    <span id="etaThresholdSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap: wrap;">
                    <span style="font-weight:700; font-size:13px; color:#1e40af; background:#dbeafe; padding:3px 9px; border-radius:6px;">Live tracking</span>
                    <span style="font-weight: 600; font-size: 13px; color: #374151;">every</span>
                    <input type="number" id="etaRefreshMinutes" value="{{ $etaRefreshMinutes }}" min="1" max="30" step="1" style="width: 70px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 700; text-align: center;">
                    <label style="font-weight: 600; font-size: 13px; color: #374151;">min</label>
                    <button onclick="saveEtaRefreshMinutes()" class="btn-add" style="padding:6px 12px; font-size:12px;">Save</button>
                    <span id="etaRefreshSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓</span>
                </div>
            </div>
            <div style="margin-top:8px; font-size:12px; color:#6b7280;">
                <b>How it behaves:</b>
                <span style="display:inline-block; padding:0 6px;">·</span> A delivery marked complete more than <b id="thresholdLabelDelay">{{ $etaDelayThresholdMin }}</b>&nbsp;min past its ETA &rarr; remaining bundles auto-recomputed
                <span style="display:inline-block; padding:0 6px;">·</span> Earliest pending ETA more than <b>{{ $etaDelayThresholdMin }}</b>&nbsp;min in the past while the planner is open &rarr; same passive recompute fires
                <span style="display:inline-block; padding:0 6px;">·</span> Riders can toggle <b>Live tracking</b> on their delivery screen for any single dispatch where they want minute-by-minute updates.
            </div>
        </div>
    </div>

    {{-- Phase 3 (May-2026) — Auto WhatsApp operational updates -------
        Master switch defaults OFF so testing before the event won't
        accidentally message real customers. Two triggers:

          1. Slaughter — fires N minutes after qurbani_slaughtered_at,
             same template for delivery + self-collection items.

          2. Out-for-Delivery — fires based on a timing rule the admin
             picks (after_status / after_dispatch / before_eta_with_buffer).
             Uses a *different* template for self-collection items so
             the customer is told "come collect" instead of "we'll deliver".

        Test phone — when filled, ALL auto messages redirect to that
        number instead of the customer. Use during dry-runs.

        Worker runs at most once every 55s globally (via Cache lock)
        and is hooked off the existing manager polling endpoints AND
        the everyMinute Laravel scheduler — no new server install needed.
    --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">📱 Auto WhatsApp Operational Updates</div>
                <div class="field-subtitle">Send template messages on Slaughter + Out-for-Delivery automatically. Master switch defaults OFF so test runs don't message real customers.</div>
            </div>
            <div>
                <span id="waAutoBadge" style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; {{ $waAutoEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#f3f4f6; color:#6b7280;' }}">
                    {{ $waAutoEnabled ? '🔴 LIVE — SENDING TO CUSTOMERS' : '⚪ OFF' }}
                </span>
            </div>
        </div>
        <div class="field-body">
            {{-- Master switch + test phone + rate cap, all on one row --}}
            <div style="padding: 14px 16px; background: {{ $waAutoEnabled ? '#fef2f2' : '#f9fafb' }}; border: 1px solid {{ $waAutoEnabled ? '#fca5a5' : '#e5e7eb' }}; border-radius: 8px; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                    <button id="waMasterBtn" onclick="toggleWaAuto()" style="padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; {{ $waAutoEnabled ? 'background:#991b1b; color:#fff;' : 'background:#10b981; color:#fff;' }}">
                        {{ $waAutoEnabled ? '🛑 Turn OFF (stop sending)' : '🟢 Turn ON (start sending)' }}
                    </button>
                    <div style="flex: 1; min-width: 200px; font-size: 12px; color: #6b7280; line-height: 1.45;">
                        {{ $waAutoEnabled
                            ? '⚠️ Auto messages ARE being sent to customers right now. Turn off if you need to make changes.'
                            : 'Turn ON only on the day of the event. Workers will not send anything while this is OFF — safe to configure templates ahead of time.' }}
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: 700; font-size: 12px; color: #92400e; background: #fef3c7; padding: 3px 9px; border-radius: 6px;">🧪 Test phone</span>
                        <input type="text" id="waTestPhone" value="{{ $waTestPhone }}" placeholder="92300xxxxxxx" maxlength="20" style="width: 180px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                        <span style="font-size: 11px; color: #6b7280;">When set, ALL messages go here instead of customers</span>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: #6b7280;">
                    <span style="font-weight: 700;">Rate cap:</span>
                    <input type="number" id="waSendMaxPerMinute" value="{{ $waSendMaxPerMinute }}" min="1" max="60" style="width: 70px; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: center;">
                    <span>messages per worker run (per minute). Defers the rest to the next run.</span>
                    <button onclick="saveWaCap()" class="btn-add" style="padding: 4px 10px; font-size: 11px;">Save</button>
                </div>
            </div>

            {{-- Slaughter trigger card --}}
            <div style="padding: 12px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div>
                        <div style="font-weight: 700; font-size: 14px; color: #92400e;">🔪 Slaughter trigger</div>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">Fires X minutes after the item status becomes Slaughtered. Same template for delivery + self-collection.</div>
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="waSlaughterEnabled" {{ $waSlaughterEnabled ? 'checked' : '' }} onchange="saveWaTriggerToggle('slaughter_enabled', this.checked)" style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-size: 13px; font-weight: 600; color: #374151;">Enabled</span>
                    </label>
                </div>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; align-items: end;">
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block;">Template (must be approved in Meta)</label>
                        <select id="waSlaughterTemplate" onchange="saveWaTriggerField('slaughter_template', this.value)" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                            <option value="">— Select template —</option>
                            @foreach($waApprovedTemplates as $tpl)
                                <option value="{{ $tpl->name }}" {{ $waSlaughterTemplate === $tpl->name ? 'selected' : '' }}>{{ $tpl->display_name }} ({{ $tpl->name }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block;">Delay (minutes after slaughter)</label>
                        <input type="number" id="waSlaughterDelayMin" value="{{ $waSlaughterDelayMin }}" min="0" max="240" onchange="saveWaTriggerField('slaughter_delay_minutes', parseInt(this.value, 10))" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-weight: 700; text-align: center;">
                    </div>
                </div>
                <div style="margin-top: 8px; font-size: 11px; color: #6b7280; line-height: 1.5;">
                    <b>Template params (in order):</b> <code>{{ '{{1}}' }}</code> = customer first name · <code>{{ '{{2}}' }}</code> = order number
                </div>
            </div>

            {{-- OFD trigger card --}}
            <div style="padding: 12px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div>
                        <div style="font-weight: 700; font-size: 14px; color: #1e40af;">🛵 Out-for-Delivery / Ready-for-Collection trigger</div>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">One trigger handles both: delivery items get the regular template; self-collection items get the "come collect" template.</div>
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="waOfdEnabled" {{ $waOfdEnabled ? 'checked' : '' }} onchange="saveWaTriggerToggle('ofd_enabled', this.checked)" style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-size: 13px; font-weight: 600; color: #374151;">Enabled</span>
                    </label>
                </div>

                {{-- Two template fields (delivery vs self-collection) --}}
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block;">Delivery template</label>
                        <select id="waOfdTemplate" onchange="saveWaTriggerField('ofd_template', this.value)" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                            <option value="">— Select template —</option>
                            @foreach($waApprovedTemplates as $tpl)
                                <option value="{{ $tpl->name }}" {{ $waOfdTemplate === $tpl->name ? 'selected' : '' }}>{{ $tpl->display_name }} ({{ $tpl->name }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block;">Self-collection template</label>
                        <select id="waOfdSelfCollectionTemplate" onchange="saveWaTriggerField('ofd_self_collection_template', this.value)" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                            <option value="">— Select template —</option>
                            @foreach($waApprovedTemplates as $tpl)
                                <option value="{{ $tpl->name }}" {{ $waOfdSelfCollectionTemplate === $tpl->name ? 'selected' : '' }}>{{ $tpl->display_name }} ({{ $tpl->name }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Require dispatched gate --}}
                <div style="margin-bottom: 12px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="waOfdRequireDispatched" {{ $waOfdRequireDispatched ? 'checked' : '' }} onchange="saveWaTriggerToggle('ofd_require_dispatched', this.checked)" style="width: 16px; height: 16px; cursor: pointer;">
                        <span style="font-size: 13px; color: #374151;"><b>Require dispatched</b> — only fire after the rider's route is dispatched (recommended)</span>
                    </label>
                </div>

                {{-- Timing mode radio + sub-fields --}}
                <div style="font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 6px;">When to send:</div>
                <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px;">
                    <label style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f9fafb; border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="waOfdTimingMode" value="after_status" {{ $waOfdTimingMode === 'after_status' ? 'checked' : '' }} onchange="onWaOfdTimingModeChange()">
                        <span style="font-size: 13px; color: #374151; flex: 1;"><b>After OFD status set</b> — fire X min after the item is marked Out for Delivery</span>
                        <input type="number" id="waOfdMinutesAfterStatus" value="{{ $waOfdMinutesAfterStatus }}" min="0" max="240" style="width: 70px; padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center;" onchange="saveWaTriggerField('ofd_minutes_after_status', parseInt(this.value, 10))">
                        <span style="font-size: 12px; color: #6b7280;">min</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f9fafb; border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="waOfdTimingMode" value="after_dispatch" {{ $waOfdTimingMode === 'after_dispatch' ? 'checked' : '' }} onchange="onWaOfdTimingModeChange()">
                        <span style="font-size: 13px; color: #374151; flex: 1;"><b>After dispatch</b> — fire X min after the dispatch button is pressed</span>
                        <input type="number" id="waOfdMinutesAfterDispatch" value="{{ $waOfdMinutesAfterDispatch }}" min="0" max="240" style="width: 70px; padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center;" onchange="saveWaTriggerField('ofd_minutes_after_dispatch', parseInt(this.value, 10))">
                        <span style="font-size: 12px; color: #6b7280;">min</span>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; background: #f9fafb; border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="waOfdTimingMode" value="before_eta_with_buffer" {{ $waOfdTimingMode === 'before_eta_with_buffer' ? 'checked' : '' }} onchange="onWaOfdTimingModeChange()" style="margin-top: 4px;">
                        <div style="flex: 1;">
                            <div style="font-size: 13px; color: #374151;"><b>Before delivery time</b> — fire X min before (Google ETA + buffer)</div>
                            <div style="margin-top: 6px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <span style="font-size: 12px; color: #6b7280;">Send</span>
                                <input type="number" id="waOfdMinutesBefore" value="{{ $waOfdMinutesBefore }}" min="0" max="120" style="width: 60px; padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center;" onchange="saveWaTriggerField('ofd_minutes_before', parseInt(this.value, 10))">
                                <span style="font-size: 12px; color: #6b7280;">min before</span>
                                <span style="font-size: 12px; color: #6b7280;">·</span>
                                <span style="font-size: 12px; color: #6b7280;">ETA buffer:</span>
                                <input type="number" id="waOfdEtaBufferMinutes" value="{{ $waOfdEtaBufferMinutes }}" min="0" max="120" style="width: 60px; padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center;" onchange="saveWaTriggerField('ofd_eta_buffer_minutes', parseInt(this.value, 10))">
                                <span style="font-size: 12px; color: #6b7280;">min added on top of Google ETA</span>
                            </div>
                        </div>
                    </label>
                </div>

                <div style="font-size: 11px; color: #6b7280; line-height: 1.55; padding: 8px 10px; background: #f9fafb; border-radius: 6px;">
                    <b>Template params (in order):</b> <code>{{ '{{1}}' }}</code> = customer first name · <code>{{ '{{2}}' }}</code> = order number · <code>{{ '{{3}}' }}</code> = delivery time text<br>
                    <b>Smart delivery time:</b> when the rider's GPS is fresh AND no earlier stop in their route slipped late, <code>{{ '{{3}}' }}</code> uses a window like <i>"7pm-8pm"</i> built from (Google ETA + buffer). Otherwise it falls back to the slot string (e.g. <i>"Afternoon 11 AM to 3 PM"</i>) so customers get a value either way.
                </div>
            </div>
        </div>
    </div>

    {{-- Rider Controls Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🏍️ Rider Controls</div>
                <div class="field-subtitle">Control what riders can do with qurbani orders</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <div style="font-weight: 600; font-size: 14px; color: #374151;">Allow Riders to Mark Delivered</div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">When disabled, riders can only collect payments on qurbani orders. Enable this during the event to allow delivery marking.</div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="riderDeliveredBadge" style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; {{ $riderDeliveredEnabled ? 'background:#d1fae5; color:#065f46;' : 'background:#f3f4f6; color:#6b7280;' }}">
                        {{ $riderDeliveredEnabled ? 'ENABLED' : 'DISABLED' }}
                    </span>
                    <button id="riderDeliveredBtn" onclick="toggleRiderDelivered()" style="padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; {{ $riderDeliveredEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#d1fae5; color:#065f46;' }}">
                        {{ $riderDeliveredEnabled ? 'Disable' : 'Enable' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Order Deletion Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🗑️ Order Deletion</div>
                <div class="field-subtitle">Allow Taimur role to permanently delete qurbani orders (including payments and ledger entries)</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <div style="font-weight: 600; font-size: 14px; color: #374151;">Allow Order Deletion</div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">When enabled, a delete button will appear in qurbani orders for the Taimur role only. This permanently removes the order and all associated data.</div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="deleteEnabledBadge" style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; {{ $deleteEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#f3f4f6; color:#6b7280;' }}">
                        {{ $deleteEnabled ? 'ENABLED' : 'DISABLED' }}
                    </span>
                    <button id="deleteEnabledBtn" onclick="toggleDeleteEnabled()" style="padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; {{ $deleteEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#d1fae5; color:#065f46;' }}">
                        {{ $deleteEnabled ? 'Disable' : 'Enable' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Cancellation Code Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🔐 Cancellation Code</div>
                <div class="field-subtitle">4-digit code required to cancel a qurbani order. Leave empty to disable (simple confirm only).</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <input type="text" id="cancellationCodeInput" value="{{ $cancellationCode }}" maxlength="4" placeholder="e.g. 1234" style="width: 120px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 18px; font-weight: 700; text-align: center; letter-spacing: 6px;">
                <button onclick="saveCancellationCode()" class="btn-add">Save</button>
                <span id="cancCodeSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
                @if($cancellationCode)
                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; background:#d1fae5; color:#065f46;">ACTIVE</span>
                @else
                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; background:#f3f4f6; color:#6b7280;">NOT SET</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Stats Category Visibility --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">📊 Stats Category Visibility</div>
                <div class="field-subtitle">Choose which product categories appear in the Booked Summary stats table. Unchecked categories will be hidden for all users (web &amp; mobile).</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; flex-wrap: wrap; gap: 10px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                @forelse($allStatsCategories as $cat)
                <label style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; font-size: 13px; font-weight: 500; user-select: none;">
                    <input type="checkbox" class="stats-cat-cb" value="{{ $cat }}" {{ !in_array($cat, $hiddenCategories) ? 'checked' : '' }} onchange="saveHiddenCategories()" style="accent-color: #059669; width: 16px; height: 16px;">
                    {{ $cat }}
                </label>
                @empty
                <span style="color: #9ca3af; font-size: 13px;">No qurbani product categories found.</span>
                @endforelse
            </div>
            <span id="hiddenCatSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600; margin-top: 6px;">✓ Saved</span>
        </div>
    </div>

    <div id="fieldsContainer">
        <div style="text-align: center; padding: 40px;"><span style="font-size: 24px;">⏳</span> Loading...</div>
    </div>
</div>
@endsection

@push('custom_js')
<script>
function toggleDeleteEnabled() {
    var btn = document.getElementById('deleteEnabledBtn');
    btn.disabled = true; btn.textContent = 'Updating...';
    fetch('/qurbani/api/toggle-delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var badge = document.getElementById('deleteEnabledBadge');
            if (data.enabled) {
                badge.textContent = 'ENABLED'; badge.style.background = '#fee2e2'; badge.style.color = '#991b1b';
                btn.textContent = 'Disable'; btn.style.background = '#fee2e2'; btn.style.color = '#991b1b';
            } else {
                badge.textContent = 'DISABLED'; badge.style.background = '#f3f4f6'; badge.style.color = '#6b7280';
                btn.textContent = 'Enable'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46';
            }
            showToast(data.message, 'success');
        } else {
            showToast('Failed to update', 'error');
        }
        btn.disabled = false;
    })
    .catch(() => { showToast('Error updating setting', 'error'); btn.disabled = false; btn.textContent = 'Retry'; });
}

function toggleRiderDelivered() {
    var btn = document.getElementById('riderDeliveredBtn');
    btn.disabled = true; btn.textContent = 'Updating...';
    fetch('/qurbani/api/toggle-rider-delivered', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var badge = document.getElementById('riderDeliveredBadge');
            if (data.enabled) {
                badge.textContent = 'ENABLED'; badge.style.background = '#d1fae5'; badge.style.color = '#065f46';
                btn.textContent = 'Disable'; btn.style.background = '#fee2e2'; btn.style.color = '#991b1b';
            } else {
                badge.textContent = 'DISABLED'; badge.style.background = '#f3f4f6'; badge.style.color = '#6b7280';
                btn.textContent = 'Enable'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46';
            }
            showToast(data.message, 'success');
        } else {
            showToast('Failed to update', 'error');
        }
        btn.disabled = false;
    })
    .catch(() => { showToast('Error updating setting', 'error'); btn.disabled = false; btn.textContent = 'Retry'; });
}

function saveHiddenCategories() {
    const checked = document.querySelectorAll('.stats-cat-cb');
    const hidden = [];
    checked.forEach(cb => { if (!cb.checked) hidden.push(cb.value); });
    fetch('{{ url("qurbani-settings/api/hidden-categories") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ hidden: hidden })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var saved = document.getElementById('hiddenCatSaved');
            saved.style.display = 'inline';
            setTimeout(() => saved.style.display = 'none', 2000);
        } else {
            showToast(data.message || 'Failed to save', 'error');
        }
    })
    .catch(() => showToast('Error saving category visibility', 'error'));
}

function saveCancellationCode() {
    const code = document.getElementById('cancellationCodeInput').value.trim();
    if (code && (code.length !== 4 || !/^\d{4}$/.test(code))) {
        showToast('Code must be exactly 4 digits', 'error');
        return;
    }
    fetch('{{ url("qurbani-settings/api/cancellation-code") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ code: code })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var saved = document.getElementById('cancCodeSaved');
            saved.style.display = 'inline';
            setTimeout(() => saved.style.display = 'none', 2000);
            showToast(data.message, 'success');
        } else {
            showToast(data.message || 'Failed to save', 'error');
        }
    })
    .catch(() => showToast('Error saving code', 'error'));
}

// NOTE: Order here also drives the render sequence on the settings page.
// qurbani_type / qurbani_paya / qurbani_item_status are simple flat dropdowns
// (no parent) so they slot in with the same renderer used for qurbani_delivery_type.
//
// qurbani_item_status drives the per-line-item lifecycle in the mobile region
// view (Open / Slaughtered / Out for Delivery / Delivered). The mobile client
// has a hard rule: NULL/empty status sorts to the very top regardless of
// display_order, and the option_value 'delivered' sorts to the very bottom.
// Renaming labels here is fine; the slug 'delivered' is what triggers the
// "always last" behaviour.
const FIELD_CONFIG = {
    qurbani_day: { label: 'Qurbani Day', icon: '📅', description: 'Day options for qurbani delivery' },
    qurbani_slot: { label: 'Qurbani Slot', icon: '🕐', description: 'Time slots (assigned per day)' },
    qurbani_region: { label: 'Qurbani Region', icon: '📍', description: 'Delivery region options' },
    qurbani_sub_region: { label: 'Sub Region', icon: '📌', description: 'Sub-regions (assigned per region)' },
    qurbani_delivery_type: { label: 'Delivery Type', icon: '🚚', description: 'Delivery or self collection' },
    qurbani_type: { label: 'Qurbani Type', icon: '🐐', description: 'Standard, custom, or your own values' },
    qurbani_paya: { label: 'Paya', icon: '🦵', description: 'Paya handling (standard, bhunnay paye, ...)' },
    qurbani_item_status: { label: 'Item Status', icon: '🏷️', description: 'Per-line-item lifecycle (open → delivered). The slug "delivered" always renders last in the mobile region view.' },
};
const FIELD_LABELS = {
    qurbani_day: 'Day', qurbani_slot: 'Slot', qurbani_region: 'Region',
    qurbani_sub_region: 'Sub Region', qurbani_delivery_type: 'Type',
    qurbani_type: 'Qurbani Type', qurbani_paya: 'Paya',
    qurbani_item_status: 'Item Status',
};

let allOptions = {};
let qurbaniCategories = [];

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Default payment method (cash/online) used when a brand-new qurbani
// order is created. Two buttons act as a radio — clicking saves
// immediately so there's no ambiguous unsaved-state. The styling
// below mirrors the server-rendered initial state for consistency.
async function setDefaultPaymentMethod(method) {
    if (method !== 'cash' && method !== 'online') return;
    const cashBtn = document.getElementById('dpmCashBtn');
    const onlineBtn = document.getElementById('dpmOnlineBtn');
    const paint = (m) => {
        if (m === 'cash') {
            cashBtn.style.background = '#10b981'; cashBtn.style.color = '#fff'; cashBtn.style.borderColor = '#10b981';
            onlineBtn.style.background = '#f3f4f6'; onlineBtn.style.color = '#374151'; onlineBtn.style.borderColor = '#d1d5db';
        } else {
            onlineBtn.style.background = '#3b82f6'; onlineBtn.style.color = '#fff'; onlineBtn.style.borderColor = '#3b82f6';
            cashBtn.style.background = '#f3f4f6'; cashBtn.style.color = '#374151'; cashBtn.style.borderColor = '#d1d5db';
        }
    };
    paint(method);
    try {
        const r = await fetch('{{ route("qurbani-settings.api.default-payment-method") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ method }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('dpmSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2000);
            showToast('Default payment method: ' + method.toUpperCase());
        } else {
            showToast('Failed to save default', 'error');
        }
    } catch (e) { showToast('Error saving', 'error'); }
}

async function saveShippingPrice() {
    const val = document.getElementById('qurbaniShippingPrice').value;
    try {
        const r = await fetch('{{ route("qurbani-settings.api.shipping-price") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ price: parseFloat(val) || 0 }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('shippingPriceSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2000);
            showToast('Delivery fee updated');
        }
    } catch (e) { showToast('Error saving', 'error'); }
}

// ----- Qurbani Operations Base (May-2026) ---------------------------
// Saves name + lat + lng as 3 ConfigModel keys. Empty = clear. Browser
// geolocation is gated on user gesture (the button click) so this
// works on both http://localhost and https:// production.
function useMyLocationForQBase() {
    if (!navigator.geolocation) { showToast('Geolocation not supported by this browser', 'error'); return; }
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            document.getElementById('qBaseLat').value = pos.coords.latitude.toFixed(6);
            document.getElementById('qBaseLng').value = pos.coords.longitude.toFixed(6);
            showToast('Location captured. Press Save Base.');
            // Reflect on the map if it's already loaded.
            if (window._qBaseMap && window._qBaseMarker) {
                const ll = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                window._qBaseMarker.setPosition(ll);
                window._qBaseMap.setCenter(ll);
                window._qBaseMap.setZoom(16);
            }
        },
        (err) => { showToast('Could not get location: ' + (err.message || 'denied'), 'error'); },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
}

// ---- Map preview / picker for the Qurbani Operations Base ---------
// Reuses the same Google Maps JS API key as attendance/locations.
// Loads on first toggle so the settings page stays fast for users
// who only need the dropdown editors.
function toggleQBaseMap() {
    const wrap = document.getElementById('qBaseMapWrap');
    const btn = document.getElementById('qBaseMapToggle');
    if (wrap.style.display === 'none' || !wrap.style.display) {
        wrap.style.display = '';
        btn.textContent = '🗺️ Hide map';
        ensureGoogleMapsLoaded(initQBaseMap);
    } else {
        wrap.style.display = 'none';
        btn.textContent = '🗺️ Show map';
    }
}

function ensureGoogleMapsLoaded(cb) {
    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
        cb();
        return;
    }
    if (window._qBaseMapsLoading) {
        // Already requesting — chain the callback.
        const prev = window._qBaseMapsLoading;
        window._qBaseMapsLoading = function() { prev(); cb(); };
        return;
    }
    window._qBaseMapsLoading = cb;
    const script = document.createElement('script');
    // Same key already used in attendance/locations.blade.php — works on prod.
    const apiKey = 'AIzaSyBFCBj7ebflrliC1pHq0XhsjuW18Q3iElk';
    script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places`;
    script.async = true;
    script.defer = true;
    script.onload = function() {
        const fn = window._qBaseMapsLoading;
        window._qBaseMapsLoading = null;
        if (typeof fn === 'function') fn();
    };
    script.onerror = function() {
        showToast('Failed to load Google Maps — check internet / API key.', 'error');
        window._qBaseMapsLoading = null;
    };
    window.gm_authFailure = function() {
        showToast('Google Maps API key was rejected. Contact admin.', 'error');
    };
    document.head.appendChild(script);
}

function initQBaseMap() {
    const container = document.getElementById('qBaseMap');
    if (!container) return;
    const lat = parseFloat(document.getElementById('qBaseLat').value) || 33.6844;
    const lng = parseFloat(document.getElementById('qBaseLng').value) || 73.0479;
    const ll = { lat: lat, lng: lng };
    const hasInitial = !!(parseFloat(document.getElementById('qBaseLat').value) && parseFloat(document.getElementById('qBaseLng').value));
    try {
        // Re-create on every toggle to keep things simple; cheap.
        window._qBaseMap = new google.maps.Map(container, {
            center: ll,
            zoom: hasInitial ? 16 : 12,
            mapTypeControl: true,
            streetViewControl: false,
            fullscreenControl: true,
            zoomControl: true,
        });
        window._qBaseMarker = new google.maps.Marker({
            position: ll,
            map: window._qBaseMap,
            draggable: true,
            title: 'Drag to set Qurbani depot location',
            animation: google.maps.Animation.DROP,
        });
        window._qBaseMap.addListener('click', (e) => {
            const p = { lat: e.latLng.lat(), lng: e.latLng.lng() };
            window._qBaseMarker.setPosition(p);
            applyMapPickToInputs(p.lat, p.lng);
        });
        window._qBaseMarker.addListener('dragend', (e) => {
            applyMapPickToInputs(e.latLng.lat(), e.latLng.lng());
        });
    } catch (e) {
        console.error('initQBaseMap', e);
        showToast('Could not initialise map.', 'error');
    }
}

function applyMapPickToInputs(lat, lng) {
    document.getElementById('qBaseLat').value = lat.toFixed(6);
    document.getElementById('qBaseLng').value = lng.toFixed(6);
}

function onQBaseInputsChanged() {
    // Keep the marker in sync if user types numbers manually after the
    // map is open. Cheap; runs on input.
    if (!window._qBaseMap || !window._qBaseMarker) return;
    const lat = parseFloat(document.getElementById('qBaseLat').value);
    const lng = parseFloat(document.getElementById('qBaseLng').value);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    const ll = { lat: lat, lng: lng };
    window._qBaseMarker.setPosition(ll);
    window._qBaseMap.panTo(ll);
}
async function saveQBaseLocation() {
    const name = document.getElementById('qBaseName').value.trim();
    const lat  = document.getElementById('qBaseLat').value.trim();
    const lng  = document.getElementById('qBaseLng').value.trim();
    if (!name || !lat || !lng) { showToast('Name, latitude and longitude are all required', 'error'); return; }
    try {
        const r = await fetch('{{ route("qurbani-settings.api.base-location") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ name: name, latitude: parseFloat(lat), longitude: parseFloat(lng) }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('qBaseSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2500);
            showToast(data.message || 'Qurbani base saved');
            // Refresh after a short pause so the badges/badge-text update
            // without surprising the user mid-toast.
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message || 'Save failed', 'error');
        }
    } catch (e) { showToast('Error saving base', 'error'); }
}
async function clearQBaseLocation() {
    if (!confirm('Clear the Qurbani Operations Base? Dispatch ETA will fall back to the regular office.')) return;
    try {
        const r = await fetch('{{ route("qurbani-settings.api.base-location") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ name: '', latitude: null, longitude: null }),
        });
        const data = await r.json();
        if (data.success) {
            showToast('Cleared');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(data.message || 'Clear failed', 'error');
        }
    } catch (e) { showToast('Error clearing', 'error'); }
}

// ----- Rider ETA auto-refresh (Phase D) -----------------------------
// Two saves: a toggle (enabled/disabled) and a numeric interval. The
// interval is bounded 1..30 minutes server-side so client validation
// is cosmetic.
async function toggleEtaRefresh() {
    const btn = document.getElementById('etaRefreshBtn');
    const badge = document.getElementById('etaRefreshBadge');
    const currentlyEnabled = badge.textContent.trim() === 'ENABLED';
    const next = !currentlyEnabled;
    btn.disabled = true; btn.textContent = 'Updating...';
    try {
        const r = await fetch('{{ route("qurbani-settings.api.eta-refresh") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ enabled: next }),
        });
        const data = await r.json();
        if (data.success) {
            if (data.enabled) {
                badge.textContent = 'ENABLED'; badge.style.background = '#d1fae5'; badge.style.color = '#065f46';
                btn.textContent = 'Disable'; btn.style.background = '#fee2e2'; btn.style.color = '#991b1b';
            } else {
                badge.textContent = 'DISABLED'; badge.style.background = '#f3f4f6'; badge.style.color = '#6b7280';
                btn.textContent = 'Enable'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46';
            }
            showToast('ETA auto-refresh ' + (data.enabled ? 'enabled' : 'disabled'));
        } else {
            showToast(data.message || 'Failed', 'error');
            btn.textContent = currentlyEnabled ? 'Disable' : 'Enable';
        }
    } catch (e) {
        showToast('Error updating', 'error');
        btn.textContent = currentlyEnabled ? 'Disable' : 'Enable';
    } finally {
        btn.disabled = false;
    }
}
async function saveEtaRefreshMinutes() {
    const val = parseInt(document.getElementById('etaRefreshMinutes').value, 10);
    if (!Number.isFinite(val) || val < 1 || val > 30) { showToast('Interval must be 1–30 minutes', 'error'); return; }
    try {
        const r = await fetch('{{ route("qurbani-settings.api.eta-refresh") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ minutes: val }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('etaRefreshSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2000);
            showToast('Live tracking interval saved');
        } else {
            showToast(data.message || 'Save failed', 'error');
        }
    } catch (e) { showToast('Error saving', 'error'); }
}

async function saveEtaDelayThreshold() {
    const val = parseInt(document.getElementById('etaDelayThresholdMin').value, 10);
    if (!Number.isFinite(val) || val < 1 || val > 60) { showToast('Threshold must be 1–60 minutes', 'error'); return; }
    try {
        const r = await fetch('{{ route("qurbani-settings.api.eta-refresh") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ delay_threshold_minutes: val }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('etaThresholdSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2000);
            const lbl = document.getElementById('thresholdLabelDelay');
            if (lbl) lbl.textContent = String(val);
            showToast('Delay threshold saved');
        } else {
            showToast(data.message || 'Save failed', 'error');
        }
    } catch (e) { showToast('Error saving', 'error'); }
}

// ─── Phase 3 (May-2026) — Auto WhatsApp settings ──────────────────
// One save function reused by all the field-level handlers. Sends
// only the changed field — server keeps the rest as-is. We always
// re-render the master badge from the response so the page stays
// in sync if another admin flipped the switch in another tab.
async function saveWaSetting(payload, opts = {}) {
    try {
        const r = await fetch('{{ route("qurbani-settings.api.wa-auto") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify(payload),
        });
        const data = await r.json();
        if (!data.success) {
            showToast(data.message || 'Save failed', 'error');
            return null;
        }
        if (opts.toast !== false) showToast(opts.toast || 'Saved');
        // Re-sync the master badge from the canonical server config.
        if (data.config) refreshWaMasterBadge(data.config);
        return data.config || null;
    } catch (e) {
        showToast('Error saving', 'error');
        return null;
    }
}

function refreshWaMasterBadge(cfg) {
    const badge = document.getElementById('waAutoBadge');
    const btn   = document.getElementById('waMasterBtn');
    if (!badge || !btn) return;
    if (cfg.master_enabled) {
        badge.style.cssText = 'padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; background:#fee2e2; color:#991b1b;';
        badge.textContent = '🔴 LIVE — SENDING TO CUSTOMERS';
        btn.style.cssText  = 'padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; background:#991b1b; color:#fff;';
        btn.textContent = '🛑 Turn OFF (stop sending)';
    } else {
        badge.style.cssText = 'padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; background:#f3f4f6; color:#6b7280;';
        badge.textContent = '⚪ OFF';
        btn.style.cssText = 'padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; background:#10b981; color:#fff;';
        btn.textContent = '🟢 Turn ON (start sending)';
    }
}

async function toggleWaAuto() {
    // We need to read the CURRENT server state (not just the badge
    // text) because the badge may have been mutated by other admins
    // in other tabs since this page loaded.
    let cfg = null;
    try {
        const r = await fetch('{{ route("qurbani-settings.api.wa-auto.get") }}', { headers: { 'Accept': 'application/json' } });
        const d = await r.json();
        if (d?.success) cfg = d.config;
    } catch {}
    const next = !(cfg ? cfg.master_enabled : false);
    if (next) {
        // Soft confirm — we want to make sure the admin really wants
        // to start sending real customer messages.
        const testPhone = (document.getElementById('waTestPhone') || {}).value || '';
        const testMode = testPhone && testPhone.trim().length > 0;
        const msg = testMode
            ? 'Turn ON auto WhatsApp. Test phone is set — messages will go to ' + testPhone.trim() + ' (NOT customers). Continue?'
            : 'Turn ON auto WhatsApp. NO test phone is set — messages WILL go to real customers as triggers fire. Continue?';
        if (!confirm(msg)) return;
    }
    // ALSO save the test phone first (in case admin typed something
    // in the box and never blurred — confirm dialog is cheap insurance).
    const tp = (document.getElementById('waTestPhone') || {}).value || '';
    await saveWaSetting({ test_phone: tp.trim() }, { toast: false });
    await saveWaSetting({ master_enabled: next }, { toast: next ? 'Auto WhatsApp turned ON' : 'Auto WhatsApp turned OFF' });
}

async function saveWaCap() {
    const val = parseInt(document.getElementById('waSendMaxPerMinute').value, 10);
    if (!Number.isFinite(val) || val < 1 || val > 60) { showToast('Rate cap must be 1–60', 'error'); return; }
    await saveWaSetting({ send_max_per_minute: val }, { toast: 'Rate cap saved' });
}

function saveWaTriggerToggle(field, value) {
    return saveWaSetting({ [field]: !!value }, { toast: !!value ? 'Trigger enabled' : 'Trigger disabled' });
}

function saveWaTriggerField(field, value) {
    if (value === '' || value === null || (typeof value === 'number' && !Number.isFinite(value))) return;
    return saveWaSetting({ [field]: value }, { toast: 'Saved' });
}

function onWaOfdTimingModeChange() {
    const val = (document.querySelector('input[name="waOfdTimingMode"]:checked') || {}).value;
    if (!val) return;
    saveWaSetting({ ofd_timing_mode: val }, { toast: 'Timing rule saved' });
}

// Save the test phone on blur (no separate Save button — leaving the
// field is the natural commit gesture).
document.addEventListener('DOMContentLoaded', () => {
    const tp = document.getElementById('waTestPhone');
    if (tp) {
        tp.addEventListener('blur', () => {
            saveWaSetting({ test_phone: tp.value.trim() }, { toast: tp.value.trim() ? 'Test phone saved' : 'Test phone cleared' });
        });
    }
});

async function loadOptions() {
    try {
        const response = await fetch('{{ route("qurbani-settings.api.options") }}');
        const data = await response.json();
        if (data.success) {
            allOptions = data.options || {};
            qurbaniCategories = data.qurbani_categories || [];
            renderAll();
        }
    } catch (error) {
        console.error('Failed to load options:', error);
        document.getElementById('fieldsContainer').innerHTML = '<div class="empty-state">Failed to load settings. Please refresh.</div>';
    }
}

function renderAll() {
    const container = document.getElementById('fieldsContainer');
    let html = '';
    const dayOptions = (allOptions['qurbani_day'] || []).filter(o => o.is_active);
    const regionOptions = (allOptions['qurbani_region'] || []).filter(o => o.is_active);
    const deliveryTypeOptions = (allOptions['qurbani_delivery_type'] || []).filter(o => o.is_active);

    for (const [fieldName, config] of Object.entries(FIELD_CONFIG)) {
        if (fieldName === 'qurbani_slot') {
            html += renderSlotSection(dayOptions, deliveryTypeOptions);
            continue;
        }
        if (fieldName === 'qurbani_sub_region') {
            html += renderDependentSection('qurbani_sub_region', '📌', 'Sub Regions', 'Sub-regions assigned per region', regionOptions, 'region', 'sub-region');
            continue;
        }
        const options = allOptions[fieldName] || [];
        const activeOptions = options.filter(o => o.is_active);
        const inactiveOptions = options.filter(o => !o.is_active);
        const showInInvoice = activeOptions.length > 0 ? (activeOptions[0].show_in_invoice ? true : false) : false;

        html += `<div class="field-section">
            <div class="field-header">
                <div>
                    <div class="field-title">${config.icon} ${config.label}</div>
                    <div class="field-subtitle">${config.description} · ${activeOptions.length} active option${activeOptions.length !== 1 ? 's' : ''}</div>
                </div>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; cursor:pointer;" title="Show this field on invoices and WhatsApp messages">
                    <input type="checkbox" ${showInInvoice ? 'checked' : ''} onchange="toggleInvoiceVisibility('${fieldName}', this.checked)" style="accent-color:#b45309;">
                    Show in Invoice
                </label>
            </div>
            <div class="field-body">`;

        if (activeOptions.length === 0 && inactiveOptions.length === 0) {
            html += '<div class="empty-state">No options yet. Add one below.</div>';
        }

        activeOptions.sort((a, b) => a.display_order - b.display_order);
        const hasCategoryDefaults = (fieldName === 'qurbani_type' || fieldName === 'qurbani_paya');
        activeOptions.forEach((opt, idx) => {
            const isDefault = opt.is_default ? true : false;
            const catOverride = opt.category_override || '';
            const catLabel = catOverride ? catOverride : 'All';
            html += `<div class="option-row" data-id="${opt.id}">
                <span class="option-order">${idx + 1}</span>
                <span class="option-value" id="val-${opt.id}">${escapeHtml(opt.option_value)}${isDefault && catOverride ? ` <span style="font-size:10px;background:#EFF6FF;color:#1D4ED8;padding:1px 6px;border-radius:4px;">Default for: ${escapeHtml(catOverride)}</span>` : ''}${isDefault && !catOverride ? ` <span style="font-size:10px;background:#D1FAE5;color:#065F46;padding:1px 6px;border-radius:4px;">Global Default</span>` : ''}</span>
                <div class="option-actions" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">`;
            if (hasCategoryDefaults) {
                html += `<select style="font-size:10px;padding:2px 4px;border:1px solid #d1d5db;border-radius:4px;max-width:110px;" onchange="setCategoryOverride(${opt.id}, this.value)" title="Default applies to">
                    <option value="">All</option>
                    ${qurbaniCategories.map(c => `<option value="${escapeAttr(c)}" ${catOverride === c ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('')}
                </select>`;
            }
            html += `<button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${opt.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                    <button class="btn-sm btn-edit" onclick="editOption(${opt.id}, '${escapeAttr(opt.option_value)}')">Edit</button>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${opt.id}, '${escapeAttr(opt.option_value)}')">Remove</button>
                </div>
            </div>`;
        });

        inactiveOptions.forEach(opt => {
            html += `<div class="option-row inactive-row" data-id="${opt.id}">
                <span class="option-order">—</span>
                <span class="option-value">${escapeHtml(opt.option_value)} <em style="font-size:11px;color:#dc2626;">(inactive)</em></span>
                <div class="option-actions">
                    <button class="btn-sm btn-restore" onclick="restoreOption(${opt.id})">Restore</button>
                </div>
            </div>`;
        });

        html += `<div class="add-form">
                <input type="text" class="add-input" id="add-${fieldName}" placeholder="New ${config.label.toLowerCase()} value..." onkeydown="if(event.key==='Enter')addOption('${fieldName}')">
                <button class="btn-add" onclick="addOption('${fieldName}')">+ Add</button>
            </div>
            </div>
        </div>`;
    }

    container.innerHTML = html;
}

function renderDependentSection(fieldName, icon, title, subtitle, parentOptions, parentLabel, childLabel) {
    const allItems = (allOptions[fieldName] || []);
    const activeItems = allItems.filter(o => o.is_active);
    const inactiveItems = allItems.filter(o => !o.is_active);
    const showInInvoice = activeItems.length > 0 ? (activeItems[0].show_in_invoice ? true : false) : false;

    let html = `<div class="field-section">
        <div class="field-header">
            <div>
                <div class="field-title">${icon} ${title}</div>
                <div class="field-subtitle">${subtitle} · ${activeItems.length} active ${childLabel}${activeItems.length !== 1 ? 's' : ''}</div>
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; cursor:pointer;" title="Show this field on invoices and WhatsApp messages">
                <input type="checkbox" ${showInInvoice ? 'checked' : ''} onchange="toggleInvoiceVisibility('${fieldName}', this.checked)" style="accent-color:#b45309;">
                Show in Invoice
            </label>
        </div>
        <div class="field-body">`;

    if (parentOptions.length === 0) {
        html += `<div class="empty-state">Add ${parentLabel} options first, then assign ${childLabel}s to each ${parentLabel}.</div>`;
    }

    parentOptions.sort((a, b) => a.display_order - b.display_order);
    parentOptions.forEach(parent => {
        const children = activeItems.filter(s => s.parent_id === parent.id).sort((a, b) => a.display_order - b.display_order);
        const parentIcon = fieldName === 'qurbani_slot' ? '📅' : '📍';
        html += `<div style="margin-bottom: 16px; padding: 12px; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px;">
            <div style="font-weight: 700; font-size: 14px; color: #92400e; margin-bottom: 8px;">${parentIcon} ${escapeHtml(parent.option_value)}</div>`;

        if (children.length === 0) {
            html += `<div style="font-size: 12px; color: #9ca3af; padding: 4px 0;">No ${childLabel}s assigned yet</div>`;
        }

        children.forEach((child, idx) => {
            const isDefault = child.is_default ? true : false;
            html += `<div class="option-row" data-id="${child.id}" style="margin-bottom: 4px;">
                <span class="option-order">${idx + 1}</span>
                <span class="option-value">${escapeHtml(child.option_value)}</span>
                <div class="option-actions">
                    <button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${child.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                    <button class="btn-sm btn-edit" onclick="editOption(${child.id}, '${escapeAttr(child.option_value)}')">Edit</button>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${child.id}, '${escapeAttr(child.option_value)}')">Remove</button>
                </div>
            </div>`;
        });

        html += `<div class="add-form" style="margin-top: 8px;">
                <input type="text" class="add-input" id="add-${fieldName}-${parent.id}" placeholder="New ${childLabel} for ${escapeAttr(parent.option_value)}..." onkeydown="if(event.key==='Enter')addChildForParent('${fieldName}', ${parent.id})">
                <button class="btn-add" onclick="addChildForParent('${fieldName}', ${parent.id})" style="padding: 6px 12px; font-size: 12px;">+ Add</button>
            </div>
        </div>`;
    });

    // Orphan items (no parent_id)
    const orphans = activeItems.filter(s => !s.parent_id);
    if (orphans.length > 0) {
        html += `<div style="margin-top: 12px; padding: 12px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 8px;">
            <div style="font-weight: 600; font-size: 13px; color: #9a3412; margin-bottom: 6px;">⚠️ Unassigned (assign to a ${parentLabel})</div>`;
        orphans.forEach(item => {
            html += `<div class="option-row" style="margin-bottom: 4px;">
                <span class="option-value">${escapeHtml(item.option_value)}</span>
                <div class="option-actions">
                    <select onchange="reassignChild(${item.id}, this.value)" style="padding: 3px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px;">
                        <option value="">Assign to ${parentLabel}...</option>
                        ${parentOptions.map(d => `<option value="${d.id}">${escapeHtml(d.option_value)}</option>`).join('')}
                    </select>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${item.id}, '${escapeAttr(item.option_value)}')">Remove</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    if (inactiveItems.length > 0) {
        html += '<div style="margin-top: 12px;">';
        inactiveItems.forEach(opt => {
            html += `<div class="option-row inactive-row" data-id="${opt.id}">
                <span class="option-order">—</span>
                <span class="option-value">${escapeHtml(opt.option_value)} <em style="font-size:11px;color:#dc2626;">(inactive)</em></span>
                <div class="option-actions">
                    <button class="btn-sm btn-restore" onclick="restoreOption(${opt.id})">Restore</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    html += '</div></div>';
    return html;
}

function renderSlotSection(dayOptions, deliveryTypeOptions) {
    const allSlots = (allOptions['qurbani_slot'] || []);
    const activeSlots = allSlots.filter(o => o.is_active);
    const inactiveSlots = allSlots.filter(o => !o.is_active);
    const showInInvoice = activeSlots.length > 0 ? (activeSlots[0].show_in_invoice ? true : false) : false;

    let html = `<div class="field-section">
        <div class="field-header">
            <div>
                <div class="field-title">🕐 Qurbani Slots</div>
                <div class="field-subtitle">Time slots assigned per day and delivery type · ${activeSlots.length} active slot${activeSlots.length !== 1 ? 's' : ''}</div>
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; cursor:pointer;" title="Show this field on invoices and WhatsApp messages">
                <input type="checkbox" ${showInInvoice ? 'checked' : ''} onchange="toggleInvoiceVisibility('qurbani_slot', this.checked)" style="accent-color:#b45309;">
                Show in Invoice
            </label>
        </div>
        <div class="field-body">`;

    if (dayOptions.length === 0) {
        html += `<div class="empty-state">Add day options first, then assign slots to each day and delivery type.</div>`;
    }

    dayOptions.sort((a, b) => a.display_order - b.display_order);
    dayOptions.forEach(day => {
        const daySlotsAll = activeSlots.filter(s => s.parent_id === day.id);

        html += `<div style="margin-bottom: 16px; padding: 12px; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px;">
            <div style="font-weight: 700; font-size: 14px; color: #92400e; margin-bottom: 10px;">📅 ${escapeHtml(day.option_value)}</div>`;

        if (deliveryTypeOptions.length === 0) {
            html += renderSlotGroupForParent(daySlotsAll, day.id, null, null, day.option_value, 'all');
        } else {
            deliveryTypeOptions.sort((a, b) => a.display_order - b.display_order);
            deliveryTypeOptions.forEach(dt => {
                const dtSlots = daySlotsAll.filter(s => s.delivery_type_parent_id === dt.id);
                html += renderSlotGroupForParent(dtSlots, day.id, dt.id, dt.option_value, day.option_value, dt.option_value);
            });

            const unlinkedSlots = daySlotsAll.filter(s => !s.delivery_type_parent_id);
            if (unlinkedSlots.length > 0) {
                html += `<div style="margin-top: 8px; padding: 8px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px;">
                    <div style="font-size: 12px; font-weight: 600; color: #9a3412; margin-bottom: 6px;">⚠️ Unlinked slots (assign to delivery type)</div>`;
                unlinkedSlots.forEach(slot => {
                    html += `<div class="option-row" data-id="${slot.id}" style="margin-bottom: 4px;">
                        <span class="option-value">${escapeHtml(slot.option_value)}</span>
                        <div class="option-actions">
                            <select onchange="reassignSlotDeliveryType(${slot.id}, this.value)" style="padding: 3px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px;">
                                <option value="">Assign type...</option>
                                ${deliveryTypeOptions.map(d => `<option value="${d.id}">${escapeHtml(d.option_value)}</option>`).join('')}
                            </select>
                            <button class="btn-sm btn-delete" onclick="deleteOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Remove</button>
                        </div>
                    </div>`;
                });
                html += `</div>`;
            }
        }

        html += `</div>`;
    });

    const orphanSlots = activeSlots.filter(s => !s.parent_id);
    if (orphanSlots.length > 0) {
        html += `<div style="margin-top: 12px; padding: 12px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 8px;">
            <div style="font-weight: 600; font-size: 13px; color: #9a3412; margin-bottom: 6px;">⚠️ Unassigned slots (assign to a day)</div>`;
        orphanSlots.forEach(item => {
            html += `<div class="option-row" style="margin-bottom: 4px;">
                <span class="option-value">${escapeHtml(item.option_value)}</span>
                <div class="option-actions">
                    <select onchange="reassignChild(${item.id}, this.value)" style="padding: 3px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px;">
                        <option value="">Assign to day...</option>
                        ${dayOptions.map(d => `<option value="${d.id}">${escapeHtml(d.option_value)}</option>`).join('')}
                    </select>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${item.id}, '${escapeAttr(item.option_value)}')">Remove</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    if (inactiveSlots.length > 0) {
        html += '<div style="margin-top: 12px;">';
        inactiveSlots.forEach(opt => {
            html += `<div class="option-row inactive-row" data-id="${opt.id}">
                <span class="option-order">—</span>
                <span class="option-value">${escapeHtml(opt.option_value)} <em style="font-size:11px;color:#dc2626;">(inactive)</em></span>
                <div class="option-actions">
                    <button class="btn-sm btn-restore" onclick="restoreOption(${opt.id})">Restore</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    html += '</div></div>';
    return html;
}

function renderSlotGroupForParent(slots, dayId, dtId, dtLabel, dayLabel, groupKey) {
    let html = `<div style="margin-bottom: 8px; padding: 8px 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;">`;
    if (dtLabel) {
        html += `<div style="font-weight: 600; font-size: 13px; color: #78350f; margin-bottom: 6px;">🚚 ${escapeHtml(dtLabel)}</div>`;
    }

    if (slots.length === 0) {
        html += `<div style="font-size: 12px; color: #9ca3af; padding: 2px 0;">No slots assigned yet</div>`;
    }

    slots.forEach((slot, idx) => {
        const isDefault = slot.is_default ? true : false;
        html += `<div class="option-row" data-id="${slot.id}" style="margin-bottom: 4px;">
            <span class="option-order">${idx + 1}</span>
            <span class="option-value">${escapeHtml(slot.option_value)}</span>
            <div class="option-actions">
                <button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${slot.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                <button class="btn-sm btn-edit" onclick="editOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Edit</button>
                <button class="btn-sm btn-delete" onclick="deleteOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Remove</button>
            </div>
        </div>`;
    });

    const inputId = dtId ? `add-slot-${dayId}-${dtId}` : `add-slot-${dayId}`;
    const placeholderSuffix = dtLabel ? ` for ${escapeAttr(dayLabel)} / ${escapeAttr(dtLabel)}` : ` for ${escapeAttr(dayLabel)}`;
    html += `<div class="add-form" style="margin-top: 6px;">
            <input type="text" class="add-input" id="${inputId}" placeholder="New slot${placeholderSuffix}..." onkeydown="if(event.key==='Enter')addSlotForDayAndType(${dayId}, ${dtId || 'null'})">
            <button class="btn-add" onclick="addSlotForDayAndType(${dayId}, ${dtId || 'null'})" style="padding: 6px 12px; font-size: 12px;">+ Add</button>
        </div>`;

    html += `</div>`;
    return html;
}

async function addSlotForDayAndType(dayId, dtId) {
    const inputId = dtId ? `add-slot-${dayId}-${dtId}` : `add-slot-${dayId}`;
    const input = document.getElementById(inputId);
    const value = input.value.trim();
    if (!value) return;
    try {
        const body = { field_name: 'qurbani_slot', option_value: value, parent_id: dayId };
        if (dtId) body.delivery_type_parent_id = dtId;
        const response = await fetch('{{ route("qurbani-settings.api.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (data.success) { showToast(data.message); input.value = ''; loadOptions(); }
        else { showToast(data.message || 'Failed', 'error'); }
    } catch (e) { showToast('Network error', 'error'); }
}

async function reassignSlotDeliveryType(slotId, deliveryTypeId) {
    if (!deliveryTypeId) return;
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${slotId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ delivery_type_parent_id: parseInt(deliveryTypeId) }),
        });
        const data = await response.json();
        if (data.success) { showToast('Delivery type assigned'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function addChildForParent(fieldName, parentId) {
    const input = document.getElementById(`add-${fieldName}-${parentId}`);
    const value = input.value.trim();
    if (!value) return;
    try {
        const response = await fetch('{{ route("qurbani-settings.api.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ field_name: fieldName, option_value: value, parent_id: parentId }),
        });
        const data = await response.json();
        if (data.success) { showToast(data.message); input.value = ''; loadOptions(); }
        else { showToast(data.message || 'Failed', 'error'); }
    } catch (e) { showToast('Network error', 'error'); }
}

async function reassignChild(itemId, parentId) {
    if (!parentId) return;
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${itemId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ parent_id: parseInt(parentId) }),
        });
        const data = await response.json();
        if (data.success) { showToast('Assigned successfully'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function toggleInvoiceVisibility(fieldName, checked) {
    const options = allOptions[fieldName] || [];
    const firstActive = options.find(o => o.is_active);
    if (!firstActive) { showToast('No options to update', 'error'); return; }
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${firstActive.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ show_in_invoice: checked }),
        });
        const data = await response.json();
        if (data.success) { showToast(checked ? 'Will show in invoice' : 'Hidden from invoice'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function toggleDefault(id, setDefault) {
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ is_default: setDefault === 'true' || setDefault === true }),
        });
        const data = await response.json();
        if (data.success) { showToast(setDefault ? 'Set as default' : 'Default removed'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function setCategoryOverride(id, category) {
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ category_override: category || null }),
        });
        const data = await response.json();
        if (data.success) { showToast(category ? `Scoped to ${category}` : 'Set to global'); loadOptions(); }
        else showToast(data.message || 'Failed', 'error');
    } catch (e) { showToast('Error updating', 'error'); }
}

async function addOption(fieldName) {
    const input = document.getElementById(`add-${fieldName}`);
    const value = input.value.trim();
    if (!value) return;

    try {
        const response = await fetch('{{ route("qurbani-settings.api.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ field_name: fieldName, option_value: value }),
        });
        const data = await response.json();
        if (data.success) {
            showToast(data.message);
            input.value = '';
            loadOptions();
        } else {
            showToast(data.message || 'Failed to add', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

async function editOption(id, currentValue) {
    const newValue = prompt('Edit value:', currentValue);
    if (!newValue || newValue.trim() === currentValue) return;

    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ option_value: newValue.trim() }),
        });
        const data = await response.json();
        if (data.success) {
            showToast('Option updated');
            loadOptions();
        } else {
            showToast(data.message || 'Failed', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

async function deleteOption(id, value) {
    if (!confirm(`Remove "${value}"? It will be deactivated (not deleted).`)) return;

    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        });
        const data = await response.json();
        if (data.success) {
            showToast('Option deactivated');
            loadOptions();
        } else {
            showToast(data.message || 'Failed', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

async function restoreOption(id) {
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ is_active: true }),
        });
        const data = await response.json();
        if (data.success) {
            showToast('Option restored');
            loadOptions();
        } else {
            showToast(data.message || 'Failed', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function escapeAttr(str) {
    return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', loadOptions);
</script>
@endpush
