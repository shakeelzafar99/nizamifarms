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
        // May-2026 — Qurbani rider whitelist. Empty / unset means
        // "show every active rider" (back-compat with installs that
        // haven't visited this section yet). When non-empty, both the
        // individual order detail picker and the bulk-rider modal in
        // the mobile Qurbani screen will only show riders whose user
        // id is in this list. Stored as a JSON array of integer IDs
        // in t_fin_config.
        $qurbaniRiderIdsJson = \App\Models\FIN\ConfigModel::get('qurbani_rider_ids', '[]');
        $qurbaniRiderIds = json_decode($qurbaniRiderIdsJson, true);
        if (!is_array($qurbaniRiderIds)) { $qurbaniRiderIds = []; }
        $qurbaniRiderIds = array_values(array_filter(array_map('intval', $qurbaniRiderIds), fn($v) => $v > 0));
        // May-2026 — Per-rider extras (region + contact). Stored in a
        // separate ConfigModel key so it survives whitelist on/off
        // toggling. Keys are stringified user_ids to match how it's
        // serialised in updateQurbaniRiderMeta.
        $qurbaniRiderMetaJson = \App\Models\FIN\ConfigModel::get('qurbani_rider_meta', '{}');
        $qurbaniRiderMeta = json_decode($qurbaniRiderMetaJson, true);
        if (!is_array($qurbaniRiderMeta)) { $qurbaniRiderMeta = []; }
        // Region options for the per-rider dropdown — same source the
        // mobile and CRM qurbani screens use (qurbani_region field
        // options table). Avoids drift between settings and ops.
        $qurbaniRegionOptions = \DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_region')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->pluck('option_value')
            ->toArray();
        // Same query as RiderController::getActiveRiders so the
        // Settings UI can offer EXACTLY the riders the mobile picker
        // would otherwise show by default.
        $allActiveRiders = \DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) { $q->whereNull('p.user_id')->orWhere('p.active', 1); })
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->get(['u.id', 'u.fullname']);
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
        // May-2026 — which delivery template body shape to use:
        //   'standard'   → 3 vars (name / order / time)
        //   'with_rider' → 5 vars (adds rider name + contact). The
        //                  Meta-approved template `qurbani_ofd_rider`
        //                  ships with the 5-var body. Must match the
        //                  template actually selected in the dropdown.
        $waOfdTemplateVariant         = (string) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_template_variant', 'standard');
        if (!in_array($waOfdTemplateVariant, ['standard', 'with_rider'], true)) {
            $waOfdTemplateVariant = 'standard';
        }
        // May-2026 rev2 — new ETA-window rule + delay-update knobs.
        $waOfdEtaWindowMinutes        = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_eta_window_minutes', '120');
        $waOfdDelayThresholdMinutes   = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_delay_threshold_minutes', '30');
        $waOfdDelayCooldownMinutes    = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_delay_resend_cooldown_minutes', '15');
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

                {{-- May-2026 — Diagnose + Run Now toolbar.
                     Mirrors what the scheduler does on every minute,
                     plus surfaces per-candidate eligibility so the
                     operator can answer "why didn't my test fire?"
                     without SSH or laravel.log greps. The Run Now
                     button is the manual cron-tick trigger — useful
                     in dev (no schedule:work running) and in prod
                     for "I just enabled this, fire it now" workflows.
                --}}
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <button onclick="diagnoseWaAuto()" type="button" style="padding: 6px 14px; background: #1e40af; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        🩺 Diagnose
                    </button>
                    <button onclick="runWaAutoNow(false)" type="button" style="padding: 6px 14px; background: #059669; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        🚀 Send Now
                    </button>
                    <button onclick="runWaAutoNow(true)" type="button" style="padding: 6px 14px; background: #b91c1c; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;" title="Releases the 55s lock first, then fires. Use if the worker says &quot;locked&quot; back-to-back.">
                        🔓 Force Send (release lock)
                    </button>
                    <span style="font-size: 11px; color: #6b7280;">
                        Diagnose explains why each candidate is/isn't eligible. Send Now fires the worker on-demand.
                    </span>
                </div>
                <div id="waDiagnoseResult" style="display: none; margin-top: 12px; padding: 12px 14px; background: #f9fafb; border: 1px solid #d1d5db; border-radius: 8px; max-height: 480px; overflow: auto;"></div>
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
                    <b>Template params (in order):</b> <code>@{{1}}</code> = customer first name · <code>@{{2}}</code> = order number
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

                {{-- May-2026 — Variant selector. Standard = 3 vars,
                     with_rider = 5 vars (qurbani_ofd_rider). Must
                     match the body of the template selected above
                     or WhatsApp rejects the send. The help text on
                     the right reminds admin which template uses which
                     shape so picking is mechanical. --}}
                <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: 12px; margin-bottom: 12px; padding: 10px 12px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px;">
                    <div>
                        <label style="font-size: 12px; font-weight: 700; color: #92400e; display: block; margin-bottom: 4px;">Delivery template variables</label>
                        <select id="waOfdTemplateVariant" onchange="saveWaTriggerField('ofd_template_variant', this.value)" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                            <option value="standard"    {{ $waOfdTemplateVariant === 'standard'   ? 'selected' : '' }}>Standard — 3 variables (name, order, time)</option>
                            <option value="with_rider"  {{ $waOfdTemplateVariant === 'with_rider' ? 'selected' : '' }}>With rider — 5 variables (+ rider name + contact)</option>
                        </select>
                    </div>
                    <div style="font-size: 11px; color: #78350f; line-height: 1.55;">
                        <b>Pick this to match the delivery template's body:</b><br>
                        • <b>Standard</b> — works with the original 3-variable template (e.g. <code>qurbani_ofd</code>): <code>@{{1}}</code> customer name · <code>@{{2}}</code> order · <code>@{{3}}</code> time.<br>
                        • <b>With rider</b> — works with the 5-variable template <code>qurbani_ofd_rider</code>: adds <code>@{{4}}</code> rider name · <code>@{{5}}</code> rider contact. The rider name is read from the user's <i>fullname</i> and the contact comes from the per-rider field below (🏍️ Qurbani Riders → contact column). Riders without a contact filled in send "(contact pending)" to the customer instead of failing.
                    </div>
                </div>

                {{-- May-2026 rev2 — New trigger rule. Replaces the old
                     timing-mode radio (after_status / after_dispatch /
                     before_eta_with_buffer). Now driven by:
                       1. Dispatch event (qurbani_dispatched_at set).
                       2. For delivery items WITH a Google ETA: hold
                          until eta is within N min of now. Default 120.
                       3. For delivery items WITHOUT an ETA (missing
                          coords case): fire immediately with the slot
                          string as the time variable.
                       4. For self-collection items: fire immediately
                          (no time variable — Meta template should only
                          have {{1}} and {{2}}).
                     Old config keys are kept in t_fin_config (unread)
                     so a future revert is a one-line change.       --}}
                <div style="margin-bottom: 12px; padding: 10px 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; font-size: 12px; color: #1e3a8a; line-height: 1.55;">
                    <b>How it fires (May-2026 rev2):</b>
                    Triggered by <b>Dispatch</b> (manager qurbani mode <i>or</i> rider Start press — same event).
                    Delivery items hold until their Google ETA is within the window below.
                    Self-collection items fire immediately on dispatch (template must use only <code>@{{1}}</code> + <code>@{{2}}</code>).
                    Items with no ETA (missing coords) fire immediately with the booking slot as the time variable.
                </div>

                {{-- ETA window + delay-update settings --}}
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div style="padding: 10px 12px; background: #f9fafb; border-radius: 6px;">
                        <label style="font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 4px;">ETA window (min)</label>
                        <input type="number" id="waOfdEtaWindowMinutes" value="{{ $waOfdEtaWindowMinutes }}" min="15" max="480" style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center; font-weight: 700;" onchange="saveWaTriggerField('ofd_eta_window_minutes', parseInt(this.value, 10))">
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.45;">Send to customers whose ETA is within this many minutes. <b>120</b> = "2 hours away".</div>
                    </div>
                    <div style="padding: 10px 12px; background: #f9fafb; border-radius: 6px;">
                        <label style="font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 4px;">Delay threshold (min)</label>
                        <input type="number" id="waOfdDelayThresholdMinutes" value="{{ $waOfdDelayThresholdMinutes }}" min="5" max="180" style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center; font-weight: 700;" onchange="saveWaTriggerField('ofd_delay_threshold_minutes', parseInt(this.value, 10))">
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.45;">"Send updated times" button on the rider's planner appears once any stop's ETA slips by this many minutes vs the time we last messaged the customer.</div>
                    </div>
                    <div style="padding: 10px 12px; background: #f9fafb; border-radius: 6px;">
                        <label style="font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 4px;">Delay-update cooldown (min)</label>
                        <input type="number" id="waOfdDelayCooldownMinutes" value="{{ $waOfdDelayCooldownMinutes }}" min="0" max="240" style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center; font-weight: 700;" onchange="saveWaTriggerField('ofd_delay_resend_cooldown_minutes', parseInt(this.value, 10))">
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.45;">Per-stop debounce — stops that received a delay-update in the last N minutes are skipped on the next manager press.</div>
                    </div>
                </div>

                <div style="font-size: 11px; color: #6b7280; line-height: 1.55; padding: 8px 10px; background: #f9fafb; border-radius: 6px;">
                    <b>Delivery template params (Standard variant):</b> <code>@{{1}}</code> = customer first name · <code>@{{2}}</code> = order number · <code>@{{3}}</code> = delivery time text<br>
                    <b>Delivery template params (With rider variant):</b> Standard + <code>@{{4}}</code> = rider name · <code>@{{5}}</code> = rider contact number<br>
                    <b>Self-collection template params:</b> <code>@{{1}}</code> = customer first name · <code>@{{2}}</code> = order number (no time variable — variant setting above is ignored for self-collection)<br>
                    <b>Delivery time text format:</b> ETA rounded DOWN to nearest 10 min, + 30 min for end of range. Example: ETA 7:32 PM → <i>"7:30 PM - 8:00 PM"</i>. Fallback for missing coords: the booking slot string (e.g. <i>"Afternoon 11 AM to 3 PM"</i>).
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

    {{-- Verified-Coords Backfill (May-2026) --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">📍 Verified Pin · Coords Backfill</div>
                <div class="field-subtitle">
                    Fixes customers whose Verified Pin badge shows but the bundle loader can't pull
                    lat/lng — usually because the pin was saved as a Google Maps short link
                    (<code>maps.app.goo.gl/&hellip;</code>) that the server-side parser can't decode without
                    following the redirect. <b>Run this once</b> on production after deploying the May-2026
                    fix to clean up historical rows. Safe to re-run — only touches rows still missing coords.
                </div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <label style="font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 6px;">
                    Batch size:
                    <input id="vcBackfillLimit" type="number" min="10" max="1000" value="200"
                           style="width: 80px; padding: 5px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: right;">
                </label>
                <button id="vcBackfillDryBtn" type="button" onclick="runVerifiedCoordsBackfill(true)"
                        class="btn-sm btn-edit" style="padding: 7px 14px;">
                    🔍 Dry Run (preview)
                </button>
                <button id="vcBackfillBtn" type="button" onclick="runVerifiedCoordsBackfill(false)"
                        class="btn-add" style="padding: 7px 14px;">
                    ▶️ Run Backfill
                </button>
                <span id="vcBackfillStatus" style="font-size: 12px; color: #6b7280; min-width: 0;"></span>
            </div>
            <div id="vcBackfillResult" style="display:none; margin-top: 10px; padding: 12px 16px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; font-size: 12px;">
                    <div style="text-align: center;">
                        <div id="vcResFixed" style="font-size: 18px; font-weight: 800; color: #059669;">0</div>
                        <div style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em;">Fixed</div>
                    </div>
                    <div style="text-align: center;">
                        <div id="vcResRemaining" style="font-size: 18px; font-weight: 800; color: #b45309;">0</div>
                        <div style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em;">Candidates Total</div>
                    </div>
                    <div style="text-align: center;">
                        <div id="vcResProcessed" style="font-size: 18px; font-weight: 800; color: #1f2937;">0</div>
                        <div style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em;">Processed (this run)</div>
                    </div>
                    <div style="text-align: center;">
                        <div id="vcResNeedAttn" style="font-size: 18px; font-weight: 800; color: #dc2626;">0</div>
                        <div style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em;">Need re-share</div>
                    </div>
                </div>
                <div id="vcResUnresolvedBlock" style="display:none; margin-top: 10px;">
                    <div style="font-size: 12px; font-weight: 700; color: #991b1b; margin-bottom: 4px;">
                        Couldn't extract a pin — ask these customers to re-share their location:
                    </div>
                    <div id="vcResUnresolvedList" style="display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: #374151; max-height: 200px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Qurbani Riders Whitelist (May-2026) --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🏍️ Qurbani Riders</div>
                <div class="field-subtitle">
                    Pick which riders show up in the mobile Qurbani screen — both the individual order picker
                    AND the bulk-assign rider dropdown read from this same list. Leave nothing checked to fall
                    back to "all active riders" (current default).
                    <br>
                    <span style="color:#4b5563; font-size:12px;">
                        Set a <b>Region</b> per rider to auto-sort the picker
                        when the operator is bulk-assigning orders from a
                        specific region (riders in that region appear at the
                        top; other-region riders are still listed under
                        "Other regions"). The <b>Contact</b> number will be
                        used in the next phase to populate the OFD WhatsApp
                        template variables.
                    </span>
                </div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                @forelse($allActiveRiders as $r)
                    @php
                        $meta = $qurbaniRiderMeta[(string) $r->id] ?? null;
                        $rRegion  = is_array($meta) ? (string) ($meta['region']  ?? '') : '';
                        $rContact = is_array($meta) ? (string) ($meta['contact'] ?? '') : '';
                        $isChecked = in_array((int) $r->id, $qurbaniRiderIds, true);
                    @endphp
                    <div class="qurbani-rider-card" data-rider-id="{{ $r->id }}"
                         style="display:flex; flex-direction:column; gap:6px; padding:10px 12px; border-radius:8px; border:1px solid {{ $isChecked ? '#fcd34d' : '#e5e7eb' }}; background:{{ $isChecked ? '#fffbeb' : '#fff' }};">
                        <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; user-select:none; color:#111827;">
                            <input
                                type="checkbox"
                                class="qurbani-rider-cb"
                                value="{{ $r->id }}"
                                {{ $isChecked ? 'checked' : '' }}
                                onchange="saveQurbaniRiders(); _toggleRiderCardChrome(this);"
                                style="accent-color: #b45309; width: 16px; height: 16px;">
                            {{ $r->fullname }}
                        </label>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <select
                                class="qurbani-rider-region"
                                data-rider-id="{{ $r->id }}"
                                onchange="saveQurbaniRiderMeta({{ $r->id }})"
                                style="flex:1; min-width:0; padding:5px 6px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; color:#374151; background:#fff;">
                                <option value="">— Region —</option>
                                @foreach($qurbaniRegionOptions as $regionOpt)
                                    <option value="{{ $regionOpt }}" {{ $rRegion === $regionOpt ? 'selected' : '' }}>{{ $regionOpt }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input
                                type="tel"
                                class="qurbani-rider-contact"
                                data-rider-id="{{ $r->id }}"
                                value="{{ $rContact }}"
                                placeholder="+92 3xx xxxxxxx"
                                oninput="saveQurbaniRiderMeta({{ $r->id }})"
                                style="flex:1; min-width:0; padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; color:#374151; background:#fff;">
                            <span class="qurbani-rider-meta-saved" data-rider-id="{{ $r->id }}" style="display:none; font-size:11px; color:#059669; font-weight:600;">✓</span>
                            <span class="qurbani-rider-meta-err" data-rider-id="{{ $r->id }}" style="display:none; font-size:11px; color:#dc2626; font-weight:600;" title="Save failed">!</span>
                        </div>
                    </div>
                @empty
                    <span style="color: #9ca3af; font-size: 13px;">No active riders found.</span>
                @endforelse
            </div>
            <div style="display: flex; align-items: center; gap: 10px; margin-top: 8px;">
                <button type="button" onclick="qurbaniRidersSelectAll(true)" class="btn-sm btn-edit">Check all</button>
                <button type="button" onclick="qurbaniRidersSelectAll(false)" class="btn-sm btn-delete">Uncheck all</button>
                <span id="qurbaniRidersSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
                <span id="qurbaniRidersErr" style="display:none; font-size: 12px; color: #dc2626; font-weight: 600;">Save failed — retry</span>
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

// May-2026 — UI wrapper for the verified-coords backfill artisan
// command. Lets the admin chip away at the historical short-link
// rows without ever needing SSH access. Batches are capped server-
// side at 1000 per click so the HTTP request stays inside the PHP
// timeout; the button auto-suggests another click when the
// candidate count is still > 0 after the run completes.
async function runVerifiedCoordsBackfill(dryRun) {
    const btnGo = document.getElementById('vcBackfillBtn');
    const btnDry = document.getElementById('vcBackfillDryBtn');
    const status = document.getElementById('vcBackfillStatus');
    const limitEl = document.getElementById('vcBackfillLimit');
    let limit = parseInt((limitEl && limitEl.value) || '200', 10);
    if (!Number.isFinite(limit) || limit < 10) limit = 10;
    if (limit > 1000) limit = 1000;

    btnGo.disabled = true; btnDry.disabled = true;
    status.style.color = '#6b7280';
    status.textContent = dryRun ? 'Running dry run…' : 'Running backfill…';

    try {
        const res = await fetch('{{ url("qurbani-settings/api/backfill-verified-coords") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ dry_run: !!dryRun, limit: limit }),
        });
        const data = await res.json();
        if (!data || !data.success) {
            throw new Error((data && data.message) || ('HTTP ' + res.status));
        }
        const r = data.result || {};
        // Populate the result tiles + unresolved list.
        const setNum = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v || 0); };
        setNum('vcResFixed',      r.fixed);
        setNum('vcResRemaining',  r.candidates);
        setNum('vcResProcessed',  r.processed);
        setNum('vcResNeedAttn',   r.unresolved_count);
        const block = document.getElementById('vcResUnresolvedBlock');
        const list = document.getElementById('vcResUnresolvedList');
        if (Array.isArray(r.unresolved) && r.unresolved.length > 0 && list) {
            const reasonMap = {
                'short_link_not_expanded':   'short link couldn\'t expand',
                'long_unparseable':          'long URL — unknown format',
                'resolved_but_unparseable':  'redirect worked but no coords',
                'network_error':             'cURL timeout / DNS error',
            };
            list.innerHTML = r.unresolved.map(u =>
                '<div>#' + u.id + ' · ' + escapeHtml(u.name || '(no name)') +
                '  <span style="color:#9ca3af;">[' + escapeHtml(reasonMap[u.reason] || u.reason) + ']</span></div>'
            ).join('');
            block.style.display = 'block';
            if (r.unresolved_count > r.unresolved.length) {
                list.innerHTML += '<div style="color:#9ca3af;">…and ' + (r.unresolved_count - r.unresolved.length) + ' more</div>';
            }
        } else if (block) {
            block.style.display = 'none';
        }
        document.getElementById('vcBackfillResult').style.display = 'block';
        status.style.color = (r.fixed > 0) ? '#059669' : '#6b7280';
        // Friendly nudge if more candidates remain (run again).
        const remaining = Math.max(0, (r.candidates || 0) - (r.processed || 0));
        if (remaining > 0 && !dryRun) {
            status.textContent = data.message + ' · ' + remaining + ' candidate(s) remaining — click "Run Backfill" again.';
        } else {
            status.textContent = data.message;
        }
    } catch (e) {
        status.style.color = '#dc2626';
        status.textContent = 'Backfill failed: ' + (e.message || 'unknown error');
    } finally {
        btnGo.disabled = false; btnDry.disabled = false;
    }
}

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// May-2026 — Qurbani Rider whitelist save handler. Debounced so a
// rapid burst of clicks (e.g. "Check all") collapses into one POST.
// Server stores the raw int[] under config key `qurbani_rider_ids`.
let _qurbaniRidersTimer = null;
function saveQurbaniRiders() {
    if (_qurbaniRidersTimer) clearTimeout(_qurbaniRidersTimer);
    _qurbaniRidersTimer = setTimeout(_qurbaniRidersFlush, 250);
}
function _qurbaniRidersFlush() {
    const ids = [];
    document.querySelectorAll('.qurbani-rider-cb').forEach(cb => {
        if (cb.checked) ids.push(parseInt(cb.value, 10));
    });
    const okBadge = document.getElementById('qurbaniRidersSaved');
    const errBadge = document.getElementById('qurbaniRidersErr');
    if (okBadge) okBadge.style.display = 'none';
    if (errBadge) errBadge.style.display = 'none';
    fetch('{{ url("qurbani-settings/api/qurbani-riders") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ rider_ids: ids })
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            if (okBadge) { okBadge.style.display = 'inline'; setTimeout(() => okBadge.style.display = 'none', 1800); }
        } else {
            if (errBadge) errBadge.style.display = 'inline';
            showToast((data && data.message) || 'Failed to save rider list', 'error');
        }
    })
    .catch(() => {
        if (errBadge) errBadge.style.display = 'inline';
        showToast('Error saving rider list', 'error');
    });
}
function qurbaniRidersSelectAll(check) {
    document.querySelectorAll('.qurbani-rider-cb').forEach(cb => {
        cb.checked = !!check;
        _toggleRiderCardChrome(cb);
    });
    saveQurbaniRiders();
}

// May-2026 — Per-rider region + contact save.
//
// Each rider card has its own debounce timer (keyed by rider_id) so
// quick typing in one rider's contact field doesn't kick off saves
// for unrelated riders. Region <select> changes also debounce on
// the same timer so a flurry of changes for the same rider collapses
// into a single POST.
//
// Server is at /qurbani-settings/api/qurbani-rider-meta and merges
// the per-rider entry into config key `qurbani_rider_meta` — see
// QurbaniSettingsController::updateQurbaniRiderMeta for the shape.
const _qurbaniRiderMetaTimers = {};
function saveQurbaniRiderMeta(riderId) {
    if (_qurbaniRiderMetaTimers[riderId]) clearTimeout(_qurbaniRiderMetaTimers[riderId]);
    _qurbaniRiderMetaTimers[riderId] = setTimeout(() => _qurbaniRiderMetaFlush(riderId), 500);
}
function _qurbaniRiderMetaFlush(riderId) {
    const regionEl  = document.querySelector('.qurbani-rider-region[data-rider-id="' + riderId + '"]');
    const contactEl = document.querySelector('.qurbani-rider-contact[data-rider-id="' + riderId + '"]');
    const okBadge   = document.querySelector('.qurbani-rider-meta-saved[data-rider-id="' + riderId + '"]');
    const errBadge  = document.querySelector('.qurbani-rider-meta-err[data-rider-id="' + riderId + '"]');
    if (okBadge)  okBadge.style.display  = 'none';
    if (errBadge) errBadge.style.display = 'none';
    const region  = regionEl  ? String(regionEl.value || '').trim() : '';
    const contact = contactEl ? String(contactEl.value || '').trim() : '';
    fetch('{{ url("qurbani-settings/api/qurbani-rider-meta") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ rider_id: riderId, region: region, contact: contact }),
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            if (okBadge) { okBadge.style.display = 'inline'; setTimeout(() => okBadge.style.display = 'none', 1500); }
        } else {
            if (errBadge) { errBadge.style.display = 'inline'; errBadge.title = (data && data.message) || 'Save failed'; }
        }
    })
    .catch(() => {
        if (errBadge) { errBadge.style.display = 'inline'; errBadge.title = 'Network error — save failed'; }
    });
}

// Visual chrome toggle on the rider card when the checkbox changes —
// purely cosmetic (highlights whitelisted riders) but keeps the panel
// scannable when only a handful of riders are enabled.
function _toggleRiderCardChrome(cb) {
    const card = cb.closest('.qurbani-rider-card');
    if (!card) return;
    if (cb.checked) {
        card.style.borderColor = '#fcd34d';
        card.style.background  = '#fffbeb';
    } else {
        card.style.borderColor = '#e5e7eb';
        card.style.background  = '#fff';
    }
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

// ─── May-2026 — Auto-WA Diagnose + Run-Now toolbar ────────────────
//
// The auto-WhatsApp worker is silent by design — it logs to the
// t_ops_qurbani_wa_log table but only writes laravel.log on actual
// errors. That means "I marked an order slaughtered and nothing
// happened" has no easy signal in the UI for an admin without SSH.
//
// These two helpers wrap the new POST /wa-auto/diagnose and
// /wa-auto/run-now endpoints (see QurbaniSettingsController) and
// render the structured response inline below the master switch so
// the admin can see config + per-candidate eligibility + recent log
// rows without leaving the page.
async function diagnoseWaAuto() {
    const out = document.getElementById('waDiagnoseResult');
    if (!out) return;
    out.style.display = 'block';
    out.innerHTML = '<div style="padding:8px;color:#6b7280;">🩺 Running diagnostic…</div>';
    try {
        const r = await fetch('{{ route("qurbani-settings.api.wa-auto.diagnose") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: '{}',
        });
        const d = await r.json();
        if (!d?.success) {
            out.innerHTML = `<div style="color:#b91c1c;font-weight:700;">❌ ${d?.message || 'Diagnose failed'}</div>`;
            return;
        }
        out.innerHTML = renderWaDiagnoseHtml(d);
    } catch (e) {
        out.innerHTML = `<div style="color:#b91c1c;font-weight:700;">❌ ${e.message}</div>`;
    }
}

async function runWaAutoNow(releaseLock) {
    const out = document.getElementById('waDiagnoseResult');
    if (!out) return;
    out.style.display = 'block';
    out.innerHTML = '<div style="padding:8px;color:#6b7280;">🚀 Firing worker…</div>';
    try {
        const r = await fetch('{{ route("qurbani-settings.api.wa-auto.run-now") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ release_lock: !!releaseLock }),
        });
        const d = await r.json();
        if (!d?.success) {
            out.innerHTML = `<div style="color:#b91c1c;font-weight:700;">❌ ${d?.message || 'Run failed'}</div>`;
            return;
        }
        const res = d.result || {};
        const rows = (d.new_log_rows || []);
        const summary = `Ran: <b>${res.ran ? 'yes' : 'no'}</b> · Sent: <b>${res.sent || 0}</b> · Skipped: <b>${res.skipped || 0}</b> · Failed: <b>${res.failed || 0}</b>${res.reason ? ' · Reason: <b>' + res.reason + '</b>' : ''}`;
        const rowsHtml = rows.length === 0
            ? '<div style="color:#6b7280;font-style:italic;padding:6px;">No new log rows in this tick (worker found nothing eligible OR worker did not run — see diagnose).</div>'
            : '<table style="width:100%;font-size:11px;border-collapse:collapse;">' +
              '<thead><tr style="background:#e5e7eb;"><th style="padding:5px;text-align:left;">Order</th><th style="padding:5px;text-align:left;">Trigger</th><th style="padding:5px;text-align:left;">Template</th><th style="padding:5px;text-align:left;">Phone</th><th style="padding:5px;text-align:left;">Status</th><th style="padding:5px;text-align:left;">Reason</th></tr></thead><tbody>' +
              rows.map(x => `<tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:5px;">${escapeHtml(x.order_number || '#' + x.line_item_id)}</td>
                <td style="padding:5px;">${escapeHtml(x.trigger_event)}</td>
                <td style="padding:5px;">${escapeHtml(x.template_name || '')}</td>
                <td style="padding:5px;">${escapeHtml(x.wa_phone || '')}</td>
                <td style="padding:5px;font-weight:700;color:${x.status === 'sent' ? '#047857' : (x.status === 'failed' ? '#b91c1c' : '#92400e')};">${escapeHtml(x.status)}</td>
                <td style="padding:5px;color:#6b7280;">${escapeHtml(x.skip_reason || '')}</td>
              </tr>`).join('') + '</tbody></table>';
        out.innerHTML = `<div style="font-weight:700;margin-bottom:6px;font-size:13px;">🚀 Worker tick complete</div><div style="margin-bottom:8px;font-size:12px;color:#374151;">${summary}</div><div style="font-size:12px;color:#6b7280;margin-bottom:6px;">${escapeHtml(d.note || '')}</div>${rowsHtml}<div style="margin-top:10px;"><button onclick="diagnoseWaAuto()" type="button" style="padding:4px 10px;background:#1e40af;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;">🩺 Run diagnose for full breakdown</button></div>`;
    } catch (e) {
        out.innerHTML = `<div style="color:#b91c1c;font-weight:700;">❌ ${e.message}</div>`;
    }
}

function renderWaDiagnoseHtml(d) {
    const escape = (s) => escapeHtml(String(s == null ? '' : s));
    const verdictColor = { READY: '#047857', WAITING: '#92400e', ALREADY_SENT: '#1e40af', NO_PHONE: '#b91c1c', BLOCKED: '#b91c1c' };
    const verdictBg = { READY: '#d1fae5', WAITING: '#fef3c7', ALREADY_SENT: '#dbeafe', NO_PHONE: '#fee2e2', BLOCKED: '#fee2e2' };

    let html = `<div style="font-weight:700;font-size:14px;margin-bottom:8px;">🩺 Auto-WhatsApp diagnostic <span style="font-weight:400;color:#6b7280;font-size:11px;">(now: ${escape(d.now)} · tz: ${escape(d.tz)})</span></div>`;

    if ((d.blockers || []).length > 0) {
        html += '<div style="padding:10px 12px;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;margin-bottom:10px;"><div style="font-weight:700;color:#991b1b;margin-bottom:4px;">🚫 Blockers</div>' +
            '<ul style="margin:0;padding-left:18px;font-size:12px;color:#7f1d1d;">' +
            d.blockers.map(b => `<li>${escape(b)}</li>`).join('') + '</ul></div>';
    } else {
        html += '<div style="padding:8px 12px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;margin-bottom:10px;font-size:12px;color:#047857;font-weight:700;">✅ No config blockers detected.</div>';
    }

    // Templates
    if (d.templates && d.templates.length) {
        html += '<div style="font-weight:700;font-size:12px;margin:10px 0 4px;">📄 Templates</div><table style="width:100%;font-size:11px;border-collapse:collapse;background:#fff;">' +
            '<thead><tr style="background:#e5e7eb;"><th style="padding:5px;text-align:left;">Name</th><th style="padding:5px;text-align:left;">In DB</th><th style="padding:5px;text-align:left;">Approved langs</th><th style="padding:5px;text-align:left;">Other status</th><th style="padding:5px;text-align:left;">Cached lang</th></tr></thead><tbody>' +
            d.templates.map(t => `<tr style="border-bottom:1px solid #f3f4f6;">
              <td style="padding:5px;font-weight:700;">${escape(t.name)}</td>
              <td style="padding:5px;">${t.exists_in_db ? '✅' : '❌'}</td>
              <td style="padding:5px;color:#047857;">${(t.approved_langs || []).map(escape).join(', ') || '—'}</td>
              <td style="padding:5px;color:#6b7280;">${(t.other_status || []).map(escape).join(', ') || '—'}</td>
              <td style="padding:5px;">${escape(t.cached_lang || '')}</td>
            </tr>`).join('') + '</tbody></table>';
    }

    // Worker
    if (d.worker) {
        const entryAgo = d.worker.last_entry_ago;
        const tickAgo  = d.worker.last_tick_ago;
        // Stale buckets — entry should be ≤8m (slowest trigger is the
        // 5-min rider heartbeat, plus a 3m grace for off-day quiet).
        const entryFresh = entryAgo !== null && entryAgo <= 8;
        const tickFresh  = tickAgo  !== null && tickAgo  <= 8;
        const dot = (fresh) => fresh ? '🟢' : '🔴';
        html += `<div style="margin-top:10px;padding:8px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:11px;color:#374151;">
            <div style="font-weight:700;margin-bottom:4px;">⚙️ Worker heartbeat</div>
            <div>${dot(entryFresh)} Last trigger entry: <b>${escape(d.worker.last_entry_at || 'never')}</b>${entryAgo !== null ? ' (' + entryAgo + 'm ago)' : ''}</div>
            <div>${dot(tickFresh)} Last actual run (lock acquired): <b>${escape(d.worker.last_tick_at || 'never')}</b>${tickAgo !== null ? ' (' + tickAgo + 'm ago)' : ''}</div>
            <div style="margin-top:4px;">🔒 Lock right now: <b>${d.worker.lock_held ? 'HELD' : 'free'}</b>${d.worker.lock_ts ? ' since ' + escape(d.worker.lock_ts) : ''}</div>
            <div style="margin-top:4px;color:#6b7280;font-size:10.5px;">${escape(d.worker.note)}</div>
        </div>`;
    }

    // Slaughter candidates
    const sc = (d.slaughter && d.slaughter.candidates) || [];
    html += `<div style="font-weight:700;font-size:12px;margin:14px 0 4px;">🔪 Slaughter candidates <span style="font-weight:400;color:#6b7280;">(mode: ${escape(d.slaughter?.mode)} · delay: ${escape(d.slaughter?.delay_min)}m · lookback: ${escape(d.slaughter?.lookback_h)}h)</span></div>`;
    if (sc.length === 0) {
        html += '<div style="padding:8px 12px;background:#f3f4f6;border-radius:6px;font-size:11px;color:#6b7280;">No items with qurbani_slaughtered_at in the last 12h. Mark an order as slaughtered to populate this list.</div>';
    } else {
        html += '<table style="width:100%;font-size:11px;border-collapse:collapse;background:#fff;"><thead><tr style="background:#e5e7eb;"><th style="padding:5px;text-align:left;">Order</th><th style="padding:5px;text-align:left;">Customer</th><th style="padding:5px;text-align:left;">Slaughtered at</th><th style="padding:5px;text-align:left;">Age</th><th style="padding:5px;text-align:left;">Eligible at</th><th style="padding:5px;text-align:left;">Phone</th><th style="padding:5px;text-align:left;">Verdict</th><th style="padding:5px;text-align:left;">Reason</th></tr></thead><tbody>';
        sc.forEach(r => {
            html += `<tr style="border-bottom:1px solid #f3f4f6;">
              <td style="padding:5px;font-weight:700;">${escape(r.order_number)}</td>
              <td style="padding:5px;">${escape(r.customer)}</td>
              <td style="padding:5px;font-family:monospace;">${escape(r.slaughtered_at)}</td>
              <td style="padding:5px;">${escape(r.minutes_since)}m</td>
              <td style="padding:5px;font-family:monospace;">${escape(r.eligible_at)}</td>
              <td style="padding:5px;font-family:monospace;">${escape(r.effective_phone)}</td>
              <td style="padding:5px;"><span style="padding:2px 8px;border-radius:10px;background:${verdictBg[r.verdict] || '#f3f4f6'};color:${verdictColor[r.verdict] || '#374151'};font-weight:700;">${escape(r.verdict)}</span></td>
              <td style="padding:5px;color:#374151;">${escape(r.reason)}</td>
            </tr>`;
        });
        html += '</tbody></table>';
    }

    // OFD
    const oc = (d.ofd && d.ofd.candidates) || [];
    html += `<div style="font-weight:700;font-size:12px;margin:14px 0 4px;">🛵 OFD candidates <span style="font-weight:400;color:#6b7280;">(mode: ${escape(d.ofd?.mode)} · window: ${escape(d.ofd?.window_min)}m · lookback: 24h)</span></div>`;
    if (oc.length === 0) {
        html += '<div style="padding:8px 12px;background:#f3f4f6;border-radius:6px;font-size:11px;color:#6b7280;">No dispatched + undelivered items in the last 24h.</div>';
    } else {
        html += '<table style="width:100%;font-size:11px;border-collapse:collapse;background:#fff;"><thead><tr style="background:#e5e7eb;"><th style="padding:5px;text-align:left;">Order</th><th style="padding:5px;text-align:left;">Customer</th><th style="padding:5px;text-align:left;">Dispatched at</th><th style="padding:5px;text-align:left;">ETA</th><th style="padding:5px;text-align:left;">Type</th><th style="padding:5px;text-align:left;">Phone</th><th style="padding:5px;text-align:left;">Verdict</th><th style="padding:5px;text-align:left;">Reason</th></tr></thead><tbody>';
        oc.forEach(r => {
            html += `<tr style="border-bottom:1px solid #f3f4f6;">
              <td style="padding:5px;font-weight:700;">${escape(r.order_number)}</td>
              <td style="padding:5px;">${escape(r.customer)}</td>
              <td style="padding:5px;font-family:monospace;">${escape(r.dispatched_at)}</td>
              <td style="padding:5px;font-family:monospace;">${escape(r.eta || '—')}</td>
              <td style="padding:5px;">${escape(r.delivery_type)}${r.is_self_collect ? ' <small style="color:#6b7280;">(self)</small>' : ''}</td>
              <td style="padding:5px;font-family:monospace;">${escape(r.effective_phone)}</td>
              <td style="padding:5px;"><span style="padding:2px 8px;border-radius:10px;background:${verdictBg[r.verdict] || '#f3f4f6'};color:${verdictColor[r.verdict] || '#374151'};font-weight:700;">${escape(r.verdict)}</span></td>
              <td style="padding:5px;color:#374151;">${escape(r.reason)}</td>
            </tr>`;
        });
        html += '</tbody></table>';
    }

    // Recent log rows
    const lg = d.recent_logs || [];
    if (lg.length) {
        html += '<div style="font-weight:700;font-size:12px;margin:14px 0 4px;">📋 Last 20 log rows</div>';
        html += '<table style="width:100%;font-size:11px;border-collapse:collapse;background:#fff;"><thead><tr style="background:#e5e7eb;"><th style="padding:5px;text-align:left;">Time</th><th style="padding:5px;text-align:left;">Order</th><th style="padding:5px;text-align:left;">Trigger</th><th style="padding:5px;text-align:left;">Template</th><th style="padding:5px;text-align:left;">Phone</th><th style="padding:5px;text-align:left;">Status</th><th style="padding:5px;text-align:left;">Reason</th></tr></thead><tbody>';
        lg.forEach(x => {
            html += `<tr style="border-bottom:1px solid #f3f4f6;">
              <td style="padding:5px;font-family:monospace;">${escape(x.created_at)}</td>
              <td style="padding:5px;">${escape(x.order_number || '#' + x.line_item_id)}</td>
              <td style="padding:5px;">${escape(x.trigger_event)}</td>
              <td style="padding:5px;">${escape(x.template_name)}</td>
              <td style="padding:5px;font-family:monospace;">${escape(x.wa_phone || '')}</td>
              <td style="padding:5px;font-weight:700;color:${x.status === 'sent' ? '#047857' : (x.status === 'failed' ? '#b91c1c' : '#92400e')};">${escape(x.status)}</td>
              <td style="padding:5px;color:#6b7280;">${escape(x.skip_reason || '')}</td>
            </tr>`;
        });
        html += '</tbody></table>';
    }

    html += '<div style="margin-top:12px;padding:8px 12px;background:#eff6ff;border-radius:6px;font-size:11px;color:#1e3a8a;">💡 Tip: if no slaughter candidate is showing READY, fix the verdict reason for the row you expected to fire, then press 🚀 Send Now.</div>';
    return html;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
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

    // Phase 4 (May-2026) — coverage hint for the slot timings card.
    // Shows how many slots have explicit start/end minutes set (from
    // either the parser auto-fill or manual edit) so the user knows
    // whether the dashboard's at-risk math will work for everything.
    const slotsWithTime = activeSlots.filter(s => s.slot_end_minute != null).length;
    const slotsWithoutTime = activeSlots.length - slotsWithTime;

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
        <div style="padding: 10px 12px; background: #fffbeb; border-bottom: 1px solid #fde68a; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div style="font-size: 12px; color: #78350f; flex: 1; min-width: 220px;">
                <strong>Slot times</strong> — used by the operations dashboard to detect late / at-risk orders.<br>
                <span style="color: ${slotsWithoutTime > 0 ? '#b91c1c' : '#15803d'}; font-weight: 600;">
                    ${slotsWithTime} of ${activeSlots.length} slot${activeSlots.length !== 1 ? 's' : ''} have times set${slotsWithoutTime > 0 ? ` · ${slotsWithoutTime} missing` : ' ✓'}
                </span>
            </div>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                <button class="btn-sm" style="background:#1e40af;color:#fff;font-size:11px;font-weight:600;padding:6px 10px;border-radius:6px;border:none;cursor:pointer;" onclick="autoDetectSlotMinutes(true)" title="Run the parser on all slots that don't have times yet — won't overwrite manual edits">
                    ✨ Auto-detect missing
                </button>
                <button class="btn-sm" style="background:#fff;color:#92400e;font-size:11px;font-weight:600;padding:6px 10px;border-radius:6px;border:1px solid #f59e0b;cursor:pointer;" onclick="autoDetectSlotMinutes(false)" title="Re-run the parser on EVERY slot, including those with manual edits. Use this if you renamed lots of slots.">
                    🔁 Re-detect all
                </button>
            </div>
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
        // Phase 4 (May-2026): show the slot's resolved start/end
        // times right next to the slot text. Green chip = times set,
        // grey chip = missing (parser will be used as fallback).
        const timeChip = formatSlotTimeChip(slot);
        html += `<div class="option-row" data-id="${slot.id}" style="margin-bottom: 4px;">
            <span class="option-order">${idx + 1}</span>
            <span class="option-value">
                ${escapeHtml(slot.option_value)}
                ${timeChip}
            </span>
            <div class="option-actions">
                <button class="btn-sm" style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:600;" onclick='openSlotTimeEditor(${JSON.stringify(slot).replace(/'/g, "&#39;")})' title="Edit slot start/end time">⏱ Time</button>
                <button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${slot.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                <button class="btn-sm btn-edit" onclick="editOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Edit</button>
                <button class="btn-sm btn-delete" onclick="deleteOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Remove</button>
            </div>
        </div>`;
    });

    const inputId = dtId ? `add-slot-${dayId}-${dtId}` : `add-slot-${dayId}`;
    const placeholderSuffix = dtLabel ? ` for ${escapeAttr(dayLabel)} / ${escapeAttr(dtLabel)}` : ` for ${escapeAttr(dayLabel)}`;
    const previewId = dtId ? `slot-preview-${dayId}-${dtId}` : `slot-preview-${dayId}`;
    html += `<div class="add-form" style="margin-top: 6px;">
            <input type="text" class="add-input" id="${inputId}" placeholder="New slot${placeholderSuffix}..." oninput="previewSlotParse('${inputId}', '${previewId}')" onkeydown="if(event.key==='Enter')addSlotForDayAndType(${dayId}, ${dtId || 'null'})">
            <button class="btn-add" onclick="addSlotForDayAndType(${dayId}, ${dtId || 'null'})" style="padding: 6px 12px; font-size: 12px;">+ Add</button>
        </div>
        <div id="${previewId}" style="font-size: 11px; color: #16a34a; margin-top: 3px; min-height: 14px;"></div>`;

    html += `</div>`;
    return html;
}

// Phase 4 (May-2026) — small chip rendered next to the slot text in
// the slots list so the resolved start/end is visible at a glance.
function formatSlotTimeChip(slot) {
    if (slot.slot_end_minute == null) {
        return `<span title="No explicit times set — line items will use parser auto-detect" style="display:inline-block;margin-left:8px;font-size:10px;background:#f3f4f6;color:#6b7280;border-radius:4px;padding:1px 6px;">⏱ no times</span>`;
    }
    const startTxt = slot.slot_start_minute != null ? minutesToHuman(slot.slot_start_minute) : '?';
    const endTxt   = minutesToHuman(slot.slot_end_minute);
    return `<span title="Slot times set in settings (used by dashboard at-risk math)" style="display:inline-block;margin-left:8px;font-size:10px;background:#dcfce7;color:#15803d;border-radius:4px;padding:1px 6px;font-weight:600;">⏱ ${startTxt} → ${endTxt}</span>`;
}

// Convert minutes-since-midnight to a 12-hour clock label.
// Mirror of App\Services\QurbaniSlotParser::formatMinutes() so the
// UI can format times client-side.
function minutesToHuman(min) {
    if (min == null || min < 0 || min > 1440) return '—';
    const h = Math.floor(min / 60);
    const m = min % 60;
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = (h % 12) || 12;
    return m === 0 ? `${h12} ${ampm}` : `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
}

// Pure JS reflection of the PHP QurbaniSlotParser. Used by the
// "preview while typing" hint AND by the auto-fill button inside
// the time editor modal. Kept simple — same regexes as the PHP side
// so the preview never disagrees with what gets stored.
function parseSlotClient(slot) {
    if (!slot) return [null, null];
    let norm = slot.trim().toUpperCase().replace(/\s+/g, ' ');
    norm = norm.replace(/^(MORNING|AFTERNOON|EVENING)\s+/, '');
    const range = norm.match(/^(\d{1,2}(?::\d{2})?)\s*(AM|PM)\s+TO\s+(\d{1,2}(?::\d{2})?)\s*(AM|PM)$/);
    if (range) {
        const s = clientTimeToMin(range[1], range[2]);
        const e = clientTimeToMin(range[3], range[4]);
        if (s == null || e == null || e < s) return [null, null];
        return [s, e];
    }
    const single = norm.match(/^(\d{1,2}(?::\d{2})?)\s*(AM|PM)$/);
    if (single) {
        const s = clientTimeToMin(single[1], single[2]);
        if (s == null) return [null, null];
        let e = s + 60;
        if (e > 1439) e = 1439;
        return [s, e];
    }
    return [null, null];
}
function clientTimeToMin(time, ampm) {
    const m = time.match(/^(\d{1,2})(?::(\d{2}))?$/);
    if (!m) return null;
    let h = parseInt(m[1], 10);
    const mm = m[2] ? parseInt(m[2], 10) : 0;
    if (h < 1 || h > 12 || mm < 0 || mm > 59) return null;
    if (h === 12) h = 0;
    if (ampm === 'PM') h += 12;
    return h * 60 + mm;
}

// Live preview while typing a new slot in the add-slot input. Tells
// the user up front whether the parser will recognise it before they
// press Add, so they can fix typos like "Afternoon 2pm to 4pm"
// (lowercase still parses fine here, but a typo like "Afternoon 2PM"
// without the space won't).
function previewSlotParse(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    if (!input || !preview) return;
    const val = input.value.trim();
    if (!val) { preview.textContent = ''; preview.style.color = '#6b7280'; return; }
    const [s, e] = parseSlotClient(val);
    if (e == null) {
        preview.textContent = "✗ Couldn't auto-detect times — you'll need to set them manually after saving.";
        preview.style.color = '#b91c1c';
    } else {
        preview.textContent = `✓ Auto-detected: ${minutesToHuman(s)} → ${minutesToHuman(e)} (you can edit after save)`;
        preview.style.color = '#15803d';
    }
}

async function addSlotForDayAndType(dayId, dtId) {
    const inputId = dtId ? `add-slot-${dayId}-${dtId}` : `add-slot-${dayId}`;
    const previewId = dtId ? `slot-preview-${dayId}-${dtId}` : `slot-preview-${dayId}`;
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
        if (data.success) {
            // Phase 4 (May-2026): if the parser couldn't auto-detect
            // times, nudge the user to set them manually now (rather
            // than silently letting the slot exist without times,
            // which would then get flagged on the dashboard).
            let msg = data.message;
            if (data.slot_end_minute == null) {
                msg += ' — couldn\'t auto-detect times, please set them manually.';
            } else {
                msg += ` (auto-detected ${minutesToHuman(data.slot_start_minute)} → ${minutesToHuman(data.slot_end_minute)})`;
            }
            showToast(msg);
            input.value = '';
            const preview = document.getElementById(previewId);
            if (preview) preview.textContent = '';
            loadOptions();
        }
        else { showToast(data.message || 'Failed', 'error'); }
    } catch (e) { showToast('Network error', 'error'); }
}

// Phase 4 (May-2026) — slot time editor modal. Opens a small popup
// that lets the user view/edit the start/end time of one slot. The
// user can pick times via two HH:MM <input type="time">, run the
// parser to auto-fill them, or clear them entirely. Saves cascade
// down to all line items using the same slot text.
window.openSlotTimeEditor = function(slot) {
    const existing = document.getElementById('slotTimeModal');
    if (existing) existing.remove();

    const startStr = slot.slot_start_minute != null ? minutesTo24h(slot.slot_start_minute) : '';
    const endStr   = slot.slot_end_minute   != null ? minutesTo24h(slot.slot_end_minute)   : '';

    const modal = document.createElement('div');
    modal.id = 'slotTimeModal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
    modal.innerHTML = `
        <div style="background:#fff;border-radius:12px;width:480px;max-width:96vw;box-shadow:0 25px 60px rgba(0,0,0,0.3);overflow:hidden;">
            <div style="padding:14px 18px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-bottom:1px solid #fbbf24;">
                <div style="font-size:11px;color:#92400e;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Edit slot times</div>
                <div style="font-size:16px;font-weight:700;color:#1f2937;margin-top:2px;">${escapeHtml(slot.option_value)}</div>
            </div>
            <div style="padding:18px;">
                <p style="font-size:12px;color:#6b7280;margin:0 0 14px 0;line-height:1.5;">
                    These times are used by the operations dashboard to detect <strong>late</strong> and <strong>at-risk</strong> orders.
                    Saving here also updates every existing order using this slot text.
                </p>
                <div style="display:flex;gap:14px;align-items:flex-end;margin-bottom:10px;">
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Start time</label>
                        <input type="time" id="slotTimeStart" value="${startStr}" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                    </div>
                    <div style="font-size:18px;color:#9ca3af;padding-bottom:8px;">→</div>
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">End time</label>
                        <input type="time" id="slotTimeEnd" value="${endStr}" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                    <button onclick="slotTimeAutoFromText(${slot.id}, '${escapeAttr(slot.option_value)}')" style="padding:5px 10px;font-size:11px;background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:6px;cursor:pointer;font-weight:600;" title="Re-run the regex parser on the slot text to fill these inputs">✨ Auto from text</button>
                    <button onclick="slotTimeClear()" style="padding:5px 10px;font-size:11px;background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;" title="Clear the inputs (line items will fall back to parser auto-detect)">✗ Clear</button>
                </div>
                <div id="slotTimeMsg" style="font-size:11px;color:#16a34a;min-height:14px;margin-bottom:12px;"></div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button onclick="document.getElementById('slotTimeModal').remove()" style="padding:7px 14px;font-size:12px;background:#fff;color:#6b7280;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">Cancel</button>
                    <button onclick="saveSlotTime(${slot.id})" style="padding:7px 14px;font-size:12px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Save</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modal);
};

// HH:MM (24h) ←→ minutes-since-midnight helpers — used by the
// modal's time inputs. <input type="time"> uses 24-hour HH:MM.
function minutesTo24h(min) {
    if (min == null) return '';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}
function timeStr24hToMinutes(s) {
    if (!s) return null;
    const m = s.match(/^(\d{2}):(\d{2})$/);
    if (!m) return null;
    return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
}

window.slotTimeAutoFromText = function(slotId, optValue) {
    const [s, e] = parseSlotClient(optValue);
    const startEl = document.getElementById('slotTimeStart');
    const endEl   = document.getElementById('slotTimeEnd');
    const msg     = document.getElementById('slotTimeMsg');
    if (e == null) {
        msg.textContent = '✗ Could not auto-detect from this slot text. Please pick times manually.';
        msg.style.color = '#b91c1c';
        return;
    }
    startEl.value = minutesTo24h(s);
    endEl.value   = minutesTo24h(e);
    msg.textContent = `✓ Filled from parser: ${minutesToHuman(s)} → ${minutesToHuman(e)}`;
    msg.style.color = '#15803d';
};
window.slotTimeClear = function() {
    document.getElementById('slotTimeStart').value = '';
    document.getElementById('slotTimeEnd').value = '';
    const msg = document.getElementById('slotTimeMsg');
    msg.textContent = 'Cleared. Save to remove the explicit times — line items will fall back to parser auto-detect.';
    msg.style.color = '#6b7280';
};
window.saveSlotTime = async function(slotId) {
    const startMin = timeStr24hToMinutes(document.getElementById('slotTimeStart').value);
    const endMin   = timeStr24hToMinutes(document.getElementById('slotTimeEnd').value);
    let body;
    if (startMin == null && endMin == null) {
        body = { action: 'clear' };
    } else if (startMin != null && endMin != null) {
        if (endMin < startMin) {
            const msg = document.getElementById('slotTimeMsg');
            msg.textContent = '✗ End time must be after start time.';
            msg.style.color = '#b91c1c';
            return;
        }
        body = { action: 'set', start_minute: startMin, end_minute: endMin };
    } else {
        const msg = document.getElementById('slotTimeMsg');
        msg.textContent = '✗ Both start and end times are required (or clear both to remove).';
        msg.style.color = '#b91c1c';
        return;
    }
    try {
        const resp = await fetch(`{{ url("qurbani-settings/api/slots") }}/${slotId}/minutes`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify(body),
        });
        const data = await resp.json();
        if (data.success) {
            showToast(data.message);
            document.getElementById('slotTimeModal').remove();
            loadOptions();
        } else {
            const msg = document.getElementById('slotTimeMsg');
            msg.textContent = '✗ ' + (data.message || 'Failed to save');
            msg.style.color = '#b91c1c';
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
};

// Phase 4 (May-2026) — bulk button at the top of the slots section.
// onlyMissing=true: skip slots that already have explicit times (default; safe).
// onlyMissing=false: re-run parser on EVERY slot (overwrites manual edits — confirm first).
window.autoDetectSlotMinutes = async function(onlyMissing) {
    if (!onlyMissing) {
        if (!confirm('Re-detect times for ALL slots? This will OVERWRITE any manual edits you\'ve made. Use this if you renamed slot text and want fresh parser values everywhere.')) {
            return;
        }
    }
    try {
        const resp = await fetch(`{{ route("qurbani-settings.api.slots-auto-detect-all") }}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ only_missing: !!onlyMissing }),
        });
        const data = await resp.json();
        if (data.success) {
            showToast(data.message);
            if (data.unparseable && data.unparseable.length) {
                console.warn('Unparseable slots:', data.unparseable);
            }
            loadOptions();
        } else {
            showToast(data.message || 'Auto-detect failed', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
};

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
