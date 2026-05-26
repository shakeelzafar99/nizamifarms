<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QurbaniSettingsController extends Controller
{
    public function index()
    {
        return view('pages.qurbani.settings');
    }

    public function getOptions()
    {
        $options = DB::table('t_crm_qurbani_field_options')
            ->orderBy('field_name')
            ->orderBy('display_order')
            ->get()
            ->groupBy('field_name');

        $shippingPrice = \App\Models\FIN\ConfigModel::get('qurbani_shipping_price', '1000');
        $defaultPaymentMethod = \App\Models\FIN\ConfigModel::get('qurbani_default_payment_method', 'cash');
        if (!in_array($defaultPaymentMethod, ['cash', 'online'], true)) {
            $defaultPaymentMethod = 'cash';
        }

        $qurbaniCategories = DB::table('t_crm_prod_product')
            ->whereRaw("LOWER(attribute_1) = 'qurbani'")
            ->whereNotNull('attribute_2')
            ->where('attribute_2', '!=', '')
            ->distinct()
            ->orderBy('attribute_2')
            ->pluck('attribute_2')
            ->toArray();

        return response()->json([
            'success' => true,
            'options' => $options,
            'qurbani_shipping_price' => $shippingPrice,
            'qurbani_default_payment_method' => $defaultPaymentMethod,
            'qurbani_categories' => $qurbaniCategories,
        ]);
    }

    public function storeOption(Request $request)
    {
        $validated = $request->validate([
            'field_name' => 'required|string|in:qurbani_day,qurbani_slot,qurbani_region,qurbani_sub_region,qurbani_delivery_type,qurbani_type,qurbani_paya,qurbani_item_status',
            'option_value' => 'required|string|max:100',
            'parent_id' => 'nullable|integer|exists:t_crm_qurbani_field_options,id',
            'delivery_type_parent_id' => 'nullable|integer|exists:t_crm_qurbani_field_options,id',
        ]);

        $maxOrder = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', $validated['field_name'])
            ->max('display_order') ?? 0;

        $query = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', $validated['field_name'])
            ->where('option_value', $validated['option_value']);

        if (isset($validated['parent_id'])) {
            $query->where('parent_id', $validated['parent_id']);
        }
        if (isset($validated['delivery_type_parent_id'])) {
            $query->where('delivery_type_parent_id', $validated['delivery_type_parent_id']);
        } else {
            $query->whereNull('delivery_type_parent_id');
        }

        $existing = $query->first();

        if ($existing) {
            if (!$existing->is_active) {
                $updateData = ['is_active' => 1, 'updated_at' => now()];
                if (isset($validated['parent_id'])) $updateData['parent_id'] = $validated['parent_id'];
                if (isset($validated['delivery_type_parent_id'])) $updateData['delivery_type_parent_id'] = $validated['delivery_type_parent_id'];
                DB::table('t_crm_qurbani_field_options')
                    ->where('id', $existing->id)
                    ->update($updateData);

                return response()->json(['success' => true, 'message' => 'Option re-activated', 'id' => $existing->id]);
            }
            return response()->json(['success' => false, 'message' => 'This option already exists'], 422);
        }

        // Phase 4 (May-2026): when adding a NEW slot, auto-detect
        // start/end minutes from the slot text using the parser, so
        // the user doesn't have to type them in. They can still edit
        // them after the row exists. For non-slot fields (Day, Region,
        // etc) the columns stay NULL.
        $insert = [
            'field_name' => $validated['field_name'],
            'option_value' => $validated['option_value'],
            'parent_id' => $validated['parent_id'] ?? null,
            'delivery_type_parent_id' => $validated['delivery_type_parent_id'] ?? null,
            'display_order' => $maxOrder + 1,
            'is_active' => 1,
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($validated['field_name'] === 'qurbani_slot') {
            [$startMin, $endMin] = \App\Services\QurbaniSlotParser::parse($validated['option_value']);
            $insert['slot_start_minute'] = $startMin;
            $insert['slot_end_minute']   = $endMin;
        }

        $id = DB::table('t_crm_qurbani_field_options')->insertGetId($insert);

        // Cascade the auto-detected times to any line items that
        // already use this exact slot text (rare for a brand-new
        // slot, but harmless and keeps the dashboard accurate).
        if ($validated['field_name'] === 'qurbani_slot' && isset($endMin) && $endMin !== null) {
            DB::table('t_crm_prod_order_line_item')
                ->where('qurbani_slot', $validated['option_value'])
                ->update([
                    'qurbani_slot_start_minute' => $startMin,
                    'qurbani_slot_end_minute'   => $endMin,
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Option added',
            'id' => $id,
            'slot_start_minute' => $insert['slot_start_minute'] ?? null,
            'slot_end_minute'   => $insert['slot_end_minute']   ?? null,
        ]);
    }

    /**
     * POST /qurbani-settings/api/slots/{id}/minutes
     *
     * Phase 4 (May-2026) — UI hook for editing the slot timings on
     * the Qurbani Settings page. Three modes:
     *   action=set    — write the supplied start/end minutes
     *   action=clear  — null both columns (line items will fall back
     *                   to parser auto-detect on next save)
     *   action=auto   — re-run QurbaniSlotParser against the slot
     *                   text and store its result
     *
     * Always cascades the resulting values down to every line item
     * with this exact slot string so dashboard queries pick the
     * change up immediately. Returns the new state + the count of
     * line items updated for the toast.
     */
    public function updateSlotMinutes(Request $request, $id)
    {
        $validated = $request->validate([
            'action'       => 'required|in:set,clear,auto',
            'start_minute' => 'nullable|integer|min:0|max:1440',
            'end_minute'   => 'nullable|integer|min:0|max:1440',
        ]);

        $option = DB::table('t_crm_qurbani_field_options')->where('id', $id)->first();
        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Option not found'], 404);
        }
        if ($option->field_name !== 'qurbani_slot') {
            return response()->json([
                'success' => false,
                'message' => 'Slot timings are only valid for slot options.',
            ], 422);
        }

        $start = null; $end = null;
        switch ($validated['action']) {
            case 'set':
                $start = $validated['start_minute'] ?? null;
                $end   = $validated['end_minute']   ?? null;
                if ($start === null || $end === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Both start_minute and end_minute are required for action=set',
                    ], 422);
                }
                if ($end < $start) {
                    return response()->json([
                        'success' => false,
                        'message' => 'End time must be after start time.',
                    ], 422);
                }
                break;
            case 'clear':
                // null/null intentionally
                break;
            case 'auto':
                [$start, $end] = \App\Services\QurbaniSlotParser::parse($option->option_value);
                if ($end === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Auto-detect failed for this slot text. Please set the times manually.',
                    ], 422);
                }
                break;
        }

        DB::table('t_crm_qurbani_field_options')
            ->where('id', $id)
            ->update([
                'slot_start_minute' => $start,
                'slot_end_minute'   => $end,
                'updated_at'        => now(),
            ]);

        // Cascade to line items using this exact slot text. We only
        // update rows whose slot text matches — this is fast because
        // qurbani_slot is short and the index on qurbani_slot_end_minute
        // doesn't matter here (we're filtering on a non-indexed
        // string, but the table is small and the operation is rare).
        $affected = DB::table('t_crm_prod_order_line_item')
            ->where('qurbani_slot', $option->option_value)
            ->update([
                'qurbani_slot_start_minute' => $start,
                'qurbani_slot_end_minute'   => $end,
            ]);

        return response()->json([
            'success' => true,
            'message' => $validated['action'] === 'clear'
                ? "Cleared slot times. {$affected} line item(s) reset."
                : "Slot times saved. {$affected} line item(s) updated.",
            'slot_start_minute'  => $start,
            'slot_end_minute'    => $end,
            'line_items_updated' => $affected,
        ]);
    }

    /**
     * POST /qurbani-settings/api/slots/auto-detect-all
     *
     * Phase 4 (May-2026) — bulk "Auto-detect minutes for all slots"
     * button. Walks every active qurbani_slot row, runs the parser,
     * and writes start/end minutes plus cascades to line items.
     *
     * Modes:
     *   only_missing=true   — only update rows where slot_end_minute is NULL
     *                         (default — won't overwrite manual edits)
     *   only_missing=false  — overwrite ALL rows with parser output (use
     *                         when you want to reset everything)
     *
     * Returns counts so the UI can render a "Updated 17 of 20 slots"
     * toast.
     */
    public function bulkAutoDetectSlotMinutes(Request $request)
    {
        $validated = $request->validate([
            'only_missing' => 'nullable|boolean',
        ]);
        $onlyMissing = (bool) ($validated['only_missing'] ?? true);

        $query = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_slot')
            ->where('is_active', 1);
        if ($onlyMissing) {
            $query->whereNull('slot_end_minute');
        }

        $rows = $query->get(['id', 'option_value']);
        $updated = 0;
        $unparseable = [];
        $totalLineItemsCascade = 0;

        foreach ($rows as $r) {
            [$start, $end] = \App\Services\QurbaniSlotParser::parse($r->option_value);
            if ($end === null) {
                $unparseable[] = $r->option_value;
                continue;
            }
            DB::table('t_crm_qurbani_field_options')
                ->where('id', $r->id)
                ->update([
                    'slot_start_minute' => $start,
                    'slot_end_minute'   => $end,
                    'updated_at'        => now(),
                ]);
            $cascadeCount = DB::table('t_crm_prod_order_line_item')
                ->where('qurbani_slot', $r->option_value)
                ->update([
                    'qurbani_slot_start_minute' => $start,
                    'qurbani_slot_end_minute'   => $end,
                ]);
            $totalLineItemsCascade += $cascadeCount;
            $updated++;
        }

        return response()->json([
            'success' => true,
            'message' => "Auto-detected {$updated} slot(s)."
                . (count($unparseable) ? ' ' . count($unparseable) . ' unparseable.' : '')
                . " {$totalLineItemsCascade} line item(s) updated.",
            'updated'              => $updated,
            'unparseable'          => $unparseable,
            'line_items_updated'   => $totalLineItemsCascade,
        ]);
    }

    public function updateOption(Request $request, $id)
    {
        $validated = $request->validate([
            'option_value' => 'sometimes|string|max:100',
            'display_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'show_in_invoice' => 'sometimes|boolean',
            'parent_id' => 'sometimes|nullable|integer',
            'delivery_type_parent_id' => 'sometimes|nullable|integer',
            'category_override' => 'sometimes|nullable|string|max:100',
        ]);

        $option = DB::table('t_crm_qurbani_field_options')->where('id', $id)->first();
        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Option not found'], 404);
        }

        $update = ['updated_at' => now()];
        if (isset($validated['option_value'])) $update['option_value'] = $validated['option_value'];
        if (isset($validated['display_order'])) $update['display_order'] = $validated['display_order'];
        if (isset($validated['is_active'])) $update['is_active'] = $validated['is_active'] ? 1 : 0;
        if (array_key_exists('parent_id', $validated)) $update['parent_id'] = $validated['parent_id'];
        if (array_key_exists('delivery_type_parent_id', $validated)) $update['delivery_type_parent_id'] = $validated['delivery_type_parent_id'];
        if (array_key_exists('category_override', $validated)) $update['category_override'] = $validated['category_override'];

        if (isset($validated['is_default'])) {
            $newDefault = $validated['is_default'] ? 1 : 0;
            if ($newDefault) {
                // Clear other defaults for the same field + same category scope
                $clearQuery = DB::table('t_crm_qurbani_field_options')
                    ->where('field_name', $option->field_name)
                    ->where('is_default', 1);

                $targetCategory = $validated['category_override'] ?? ($option->category_override ?? null);
                if ($targetCategory) {
                    $clearQuery->where('category_override', $targetCategory);
                } else {
                    $clearQuery->where(function ($q) {
                        $q->whereNull('category_override')->orWhere('category_override', '');
                    });
                }
                $clearQuery->update(['is_default' => 0, 'updated_at' => now()]);
            }
            $update['is_default'] = $newDefault;
        }

        if (isset($validated['show_in_invoice'])) {
            $val = $validated['show_in_invoice'] ? 1 : 0;
            DB::table('t_crm_qurbani_field_options')
                ->where('field_name', $option->field_name)
                ->update(['show_in_invoice' => $val, 'updated_at' => now()]);
            $update['show_in_invoice'] = $val;
        }

        DB::table('t_crm_qurbani_field_options')->where('id', $id)->update($update);

        return response()->json(['success' => true, 'message' => 'Option updated']);
    }

    public function deleteOption($id)
    {
        $option = DB::table('t_crm_qurbani_field_options')->where('id', $id)->first();
        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Option not found'], 404);
        }

        DB::table('t_crm_qurbani_field_options')
            ->where('id', $id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Option deactivated']);
    }

    public function updateShippingPrice(Request $request)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);

        \App\Models\FIN\ConfigModel::set('qurbani_shipping_price', $validated['price'], 'Default delivery fee for qurbani orders');

        return response()->json(['success' => true, 'message' => 'Delivery fee updated', 'price' => $validated['price']]);
    }

    /**
     * Set the default payment method used when a new qurbani order is
     * created (web + mobile read this to pre-select the radio/select).
     * Only 'cash' and 'online' are valid because the Qurbani payment
     * modal is constrained to those two methods.
     */
    public function updateDefaultPaymentMethod(Request $request)
    {
        $validated = $request->validate([
            'method' => 'required|string|in:cash,online',
        ]);

        \App\Models\FIN\ConfigModel::set(
            'qurbani_default_payment_method',
            $validated['method'],
            'Default payment method pre-selected when creating a new qurbani order'
        );

        return response()->json([
            'success' => true,
            'message' => 'Default payment method updated',
            'method' => $validated['method'],
        ]);
    }

    public function updateCancellationCode(Request $request)
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:4',
        ]);

        $code = $validated['code'] ?? '';
        if ($code !== '' && (!ctype_digit($code) || strlen($code) !== 4)) {
            return response()->json(['success' => false, 'message' => 'Code must be exactly 4 digits'], 422);
        }

        \App\Models\FIN\ConfigModel::set(
            'qurbani_cancellation_code',
            $code,
            '4-digit code required to cancel qurbani orders'
        );

        return response()->json([
            'success' => true,
            'message' => $code ? 'Cancellation code saved' : 'Cancellation code removed',
        ]);
    }

    /**
     * Save the Qurbani Operations Base location (used as the origin
     * fallback for ETA dispatch when the rider's GPS is stale, and as
     * the final waypoint for the "Return to base" ETA the rider screen
     * surfaces after the last delivery).
     *
     * Stored as 3 separate ConfigModel keys (name / lat / lng) rather
     * than a row in t_ops_company_locations because Qurbani is
     * seasonal and we don't want this depot showing up in attendance
     * pickers. Pass empty/null values to CLEAR the base.
     */
    public function updateBaseLocation(Request $request)
    {
        $validated = $request->validate([
            'name'      => 'nullable|string|max:120',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $name = trim((string) ($validated['name'] ?? ''));
        $lat  = $validated['latitude']  ?? null;
        $lng  = $validated['longitude'] ?? null;

        // Treat "all empty" as a clear request — wipes the override so
        // the dispatch flow falls back to the generic office.
        $clear = ($name === '' && ($lat === null || $lat === '') && ($lng === null || $lng === ''));
        if ($clear) {
            \App\Models\FIN\ConfigModel::set('qurbani_base_name', '', 'Cleared Qurbani Operations Base');
            \App\Models\FIN\ConfigModel::set('qurbani_base_lat',  '', 'Cleared Qurbani Operations Base');
            \App\Models\FIN\ConfigModel::set('qurbani_base_lng',  '', 'Cleared Qurbani Operations Base');
            return response()->json([
                'success' => true,
                'message' => 'Qurbani base cleared. ETA will fall back to office.',
                'base'    => null,
            ]);
        }

        // Partial save is invalid — we need all three to compute ETA.
        if ($name === '' || $lat === null || $lng === null) {
            return response()->json([
                'success' => false,
                'message' => 'Name, latitude and longitude are all required.',
            ], 422);
        }

        \App\Models\FIN\ConfigModel::set('qurbani_base_name', $name, 'Qurbani Operations Base — display name');
        \App\Models\FIN\ConfigModel::set('qurbani_base_lat',  (string) $lat, 'Qurbani Operations Base — latitude');
        \App\Models\FIN\ConfigModel::set('qurbani_base_lng',  (string) $lng, 'Qurbani Operations Base — longitude');

        return response()->json([
            'success' => true,
            'message' => 'Qurbani base saved.',
            'base'    => [
                'name'      => $name,
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ],
        ]);
    }

    /**
     * Configure the rider-side ETA behaviour for Qurbani.
     *
     * Three keys:
     *   qurbani_eta_refresh_enabled        (0/1)      — master switch (admin) gating live tracking + delay detection
     *   qurbani_eta_refresh_minutes        (int 1..30) — interval used WHEN a rider opts into "live tracking" per dispatch (default 3)
     *   qurbani_eta_delay_threshold_minutes (int 1..60) — how many minutes a delivery (or current time vs. earliest pending ETA) must
     *                                                   slip past the stored ETA before we auto-recompute the rest of the dispatch (default 10)
     *
     * Live tracking is opt-in PER DISPATCH on the rider's screen.
     * Delay detection runs automatically (server-side) on delivery
     * completion + on planner/summary polls. Both are gated by the
     * master switch and share the existing 1/min/rider rate-limit.
     */
    public function updateEtaRefresh(Request $request)
    {
        $validated = $request->validate([
            'enabled'                  => 'nullable|boolean',
            'minutes'                  => 'nullable|integer|min:1|max:30',
            'delay_threshold_minutes'  => 'nullable|integer|min:1|max:60',
        ]);

        if (array_key_exists('enabled', $validated)) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_eta_refresh_enabled',
                $validated['enabled'] ? '1' : '0',
                'Master switch — gates Qurbani live ETA tracking + delay detection'
            );
        }
        if (array_key_exists('minutes', $validated) && $validated['minutes'] !== null) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_eta_refresh_minutes',
                (string) $validated['minutes'],
                'Interval (minutes) used when a rider opts into per-dispatch Live ETA tracking'
            );
        }
        if (array_key_exists('delay_threshold_minutes', $validated) && $validated['delay_threshold_minutes'] !== null) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_eta_delay_threshold_minutes',
                (string) $validated['delay_threshold_minutes'],
                'Minutes past stored ETA before Qurbani dispatch ETAs are auto-recomputed for the rest of the batch'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'ETA settings saved.',
            'enabled' => \App\Models\FIN\ConfigModel::get('qurbani_eta_refresh_enabled', '1') === '1',
            'minutes' => (int) \App\Models\FIN\ConfigModel::get('qurbani_eta_refresh_minutes', '3'),
            'delay_threshold_minutes' => (int) \App\Models\FIN\ConfigModel::get('qurbani_eta_delay_threshold_minutes', '10'),
        ]);
    }

    /**
     * POST /qurbani-settings/api/wa-auto
     * Phase 3 (May-2026) — save the Qurbani auto-WhatsApp settings.
     *
     * Stores all keys via ConfigModel — no schema change. The worker
     * (\App\Services\QurbaniWaAutoSender) reads these on every run.
     *
     * Master switch (qurbani_wa_auto_enabled) defaults OFF on a fresh
     * install so test runs before the event don't actually send
     * anything. Turning it ON activates the slaughter + OFD triggers
     * (each gated by their own enabled flag).
     *
     * Validation is permissive on the optional fields — a user who
     * turns on the master switch but hasn't picked a template yet
     * will see "skipped: template_missing" rows in the wa-log; we
     * don't refuse the save (preserves their work-in-progress).
     */
    public function updateWaAuto(Request $request)
    {
        $validated = $request->validate([
            'master_enabled'                       => 'nullable|boolean',
            'test_phone'                           => 'nullable|string|max:20',
            'send_max_per_minute'                  => 'nullable|integer|min:1|max:60',

            'slaughter_enabled'                    => 'nullable|boolean',
            'slaughter_template'                   => 'nullable|string|max:100',
            'slaughter_delay_minutes'              => 'nullable|integer|min:0|max:240',

            'ofd_enabled'                          => 'nullable|boolean',
            'ofd_template'                         => 'nullable|string|max:100',
            'ofd_self_collection_template'         => 'nullable|string|max:100',
            // May-2026 — selects which body-param shape we send to the
            // delivery template: 'standard' = 3 vars (name/order/time),
            // 'with_rider' = 5 vars (adds rider name + contact). Must
            // match the actual Meta-approved template body — picking
            // with_rider but pointing `ofd_template` at a 3-var template
            // will cause WhatsApp to reject the send.
            'ofd_template_variant'                 => 'nullable|in:standard,with_rider',
            'ofd_require_dispatched'               => 'nullable|boolean',
            'ofd_timing_mode'                      => 'nullable|in:after_status,after_dispatch,before_eta_with_buffer',
            'ofd_minutes_after_status'             => 'nullable|integer|min:0|max:240',
            'ofd_minutes_after_dispatch'           => 'nullable|integer|min:0|max:240',
            'ofd_eta_buffer_minutes'               => 'nullable|integer|min:0|max:120',
            'ofd_minutes_before'                   => 'nullable|integer|min:0|max:120',
            // May-2026 rev2 — new ETA-window rule + delay-update knobs.
            'ofd_eta_window_minutes'               => 'nullable|integer|min:15|max:480',
            'ofd_delay_threshold_minutes'          => 'nullable|integer|min:5|max:180',
            'ofd_delay_resend_cooldown_minutes'    => 'nullable|integer|min:0|max:240',
        ]);

        // Helper closure so we don't repeat the if-set-then-save dance
        // for every key. Treats null/missing as "leave as is" — only
        // saves keys explicitly present in the payload.
        $set = function (string $key, $value, string $description) use ($validated) {
            if (!array_key_exists(str_replace('qurbani_wa_', '', $key), $validated)) return;
            $payloadKey = str_replace('qurbani_wa_', '', $key);
            $v = $validated[$payloadKey];
            if ($v === null) return;
            \App\Models\FIN\ConfigModel::set($key, (string) $v, $description);
        };

        // Booleans need conversion to '1'/'0'.
        $setBool = function (string $key, $payloadKey, string $description) use ($validated) {
            if (!array_key_exists($payloadKey, $validated)) return;
            $v = $validated[$payloadKey];
            if ($v === null) return;
            \App\Models\FIN\ConfigModel::set($key, $v ? '1' : '0', $description);
        };

        $setBool('qurbani_wa_auto_enabled',                'master_enabled',
            'Master switch — turn ON to activate Qurbani auto WhatsApp messages on the day of the event');
        if (array_key_exists('test_phone', $validated)) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_wa_test_phone',
                (string) ($validated['test_phone'] ?? ''),
                'When set, ALL auto WhatsApp messages redirect to this phone instead of customers (testing mode)'
            );
        }
        if (array_key_exists('send_max_per_minute', $validated) && $validated['send_max_per_minute'] !== null) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_wa_send_max_per_minute',
                (string) $validated['send_max_per_minute'],
                'Cap on messages sent per worker run (per minute) — protects WhatsApp API and printer-style throttling'
            );
        }

        // Slaughter trigger.
        $setBool('qurbani_wa_slaughter_enabled', 'slaughter_enabled',
            'Auto-WA: send a message N minutes after item is marked Slaughtered');
        if (array_key_exists('slaughter_template', $validated)) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_wa_slaughter_template',
                (string) ($validated['slaughter_template'] ?? ''),
                'Auto-WA Slaughter trigger: WhatsApp template name (must be approved in Meta + present in t_wa_templates)'
            );
        }
        if (array_key_exists('slaughter_delay_minutes', $validated) && $validated['slaughter_delay_minutes'] !== null) {
            \App\Models\FIN\ConfigModel::set(
                'qurbani_wa_slaughter_delay_minutes',
                (string) $validated['slaughter_delay_minutes'],
                'Auto-WA Slaughter trigger: minutes to wait after qurbani_slaughtered_at before firing'
            );
        }

        // OFD trigger.
        $setBool('qurbani_wa_ofd_enabled', 'ofd_enabled',
            'Auto-WA: send Out-for-Delivery / Ready-for-Collection message based on timing rules');
        $setBool('qurbani_wa_ofd_require_dispatched', 'ofd_require_dispatched',
            'Auto-WA OFD trigger: also require qurbani_dispatched_at to be set');
        $stringFields = [
            'ofd_template'                  => ['qurbani_wa_ofd_template',                  'Auto-WA OFD trigger: template for delivery items'],
            'ofd_self_collection_template'  => ['qurbani_wa_ofd_self_collection_template',  'Auto-WA OFD trigger: template for self-collection items'],
            'ofd_timing_mode'               => ['qurbani_wa_ofd_timing_mode',               'Auto-WA OFD trigger: which timing rule fires the message'],
            // May-2026 — see comment in updateWaAuto validation block.
            'ofd_template_variant'          => ['qurbani_wa_ofd_template_variant',          'Auto-WA OFD trigger: delivery template variant (standard=3-var, with_rider=5-var qurbani_ofd_rider style)'],
        ];
        foreach ($stringFields as $payloadKey => [$cfgKey, $desc]) {
            if (!array_key_exists($payloadKey, $validated)) continue;
            \App\Models\FIN\ConfigModel::set($cfgKey, (string) ($validated[$payloadKey] ?? ''), $desc);
        }
        $intFields = [
            'ofd_minutes_after_status'    => ['qurbani_wa_ofd_minutes_after_status',    'Auto-WA OFD: when timing_mode=after_status, fire X min after qurbani_out_for_delivery_at (legacy — May-2026 rev2 no longer reads this)'],
            'ofd_minutes_after_dispatch'  => ['qurbani_wa_ofd_minutes_after_dispatch',  'Auto-WA OFD: when timing_mode=after_dispatch, fire X min after qurbani_dispatched_at (legacy)'],
            'ofd_eta_buffer_minutes'      => ['qurbani_wa_ofd_eta_buffer_minutes',      'Auto-WA OFD: buffer added on top of Google ETA when computing the "expected delivery time" (legacy)'],
            'ofd_minutes_before'          => ['qurbani_wa_ofd_minutes_before',          'Auto-WA OFD: when timing_mode=before_eta_with_buffer, fire X min before (eta + buffer) (legacy)'],
            // May-2026 rev2 — active fields.
            'ofd_eta_window_minutes'           => ['qurbani_wa_ofd_eta_window_minutes',           'Auto-WA OFD (rev2): only fire when ETA is within this many minutes of now (default 120 = 2h)'],
            'ofd_delay_threshold_minutes'      => ['qurbani_wa_ofd_delay_threshold_minutes',      'Auto-WA OFD (rev2): manager "Send updated times" banner appears when ETA slips by more than this many minutes vs last messaged ETA (default 30)'],
            'ofd_delay_resend_cooldown_minutes'=> ['qurbani_wa_ofd_delay_resend_cooldown_minutes', 'Auto-WA OFD (rev2): per-line-item cooldown for the manual delay-update so a single button press cannot spam (default 15)'],
        ];
        foreach ($intFields as $payloadKey => [$cfgKey, $desc]) {
            if (!array_key_exists($payloadKey, $validated)) continue;
            $v = $validated[$payloadKey];
            if ($v === null) continue;
            \App\Models\FIN\ConfigModel::set($cfgKey, (string) $v, $desc);
        }

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp auto-update settings saved.',
            'config'  => $this->loadWaAutoConfig(),
        ]);
    }

    /**
     * GET /qurbani-settings/api/wa-auto
     * Returns the current snapshot of all qurbani_wa_* config keys.
     * Same shape the blade reads at boot, so the JS can refresh
     * its widgets after a save without a page reload.
     */
    public function getWaAuto()
    {
        return response()->json(['success' => true, 'config' => $this->loadWaAutoConfig()]);
    }

    /**
     * Internal helper: snapshot of the auto-WA config. Defaults are
     * applied here so the worker / blade / API all agree on values
     * for keys that were never saved.
     */
    private function loadWaAutoConfig(): array
    {
        $get  = fn(string $k, $d = null) => \App\Models\FIN\ConfigModel::get($k, $d);
        $bool = fn(string $k, bool $d = false) => \App\Models\FIN\ConfigModel::get($k, $d ? '1' : '0') === '1';
        $int  = fn(string $k, int $d) => (int) \App\Models\FIN\ConfigModel::get($k, (string) $d);

        return [
            'master_enabled'                       => $bool('qurbani_wa_auto_enabled', false),
            'test_phone'                           => (string) $get('qurbani_wa_test_phone', ''),
            'send_max_per_minute'                  => $int('qurbani_wa_send_max_per_minute', 10),

            'slaughter_enabled'                    => $bool('qurbani_wa_slaughter_enabled', false),
            'slaughter_template'                   => (string) $get('qurbani_wa_slaughter_template', ''),
            'slaughter_delay_minutes'              => $int('qurbani_wa_slaughter_delay_minutes', 30),

            'ofd_enabled'                          => $bool('qurbani_wa_ofd_enabled', false),
            'ofd_template'                         => (string) $get('qurbani_wa_ofd_template', ''),
            'ofd_self_collection_template'         => (string) $get('qurbani_wa_ofd_self_collection_template', ''),
            // May-2026 — see QurbaniWaAutoSender::buildOfdParams.
            'ofd_template_variant'                 => (string) $get('qurbani_wa_ofd_template_variant', 'standard'),
            'ofd_require_dispatched'               => $bool('qurbani_wa_ofd_require_dispatched', true),
            'ofd_timing_mode'                      => (string) $get('qurbani_wa_ofd_timing_mode', 'before_eta_with_buffer'),
            'ofd_minutes_after_status'             => $int('qurbani_wa_ofd_minutes_after_status', 0),
            'ofd_minutes_after_dispatch'           => $int('qurbani_wa_ofd_minutes_after_dispatch', 30),
            'ofd_eta_buffer_minutes'               => $int('qurbani_wa_ofd_eta_buffer_minutes', 15),
            'ofd_minutes_before'                   => $int('qurbani_wa_ofd_minutes_before', 15),
            // May-2026 rev2 — new ETA-window rule + delay-update knobs.
            'ofd_eta_window_minutes'               => $int('qurbani_wa_ofd_eta_window_minutes', 120),
            'ofd_delay_threshold_minutes'          => $int('qurbani_wa_ofd_delay_threshold_minutes', 30),
            'ofd_delay_resend_cooldown_minutes'    => $int('qurbani_wa_ofd_delay_resend_cooldown_minutes', 15),
        ];
    }

    /**
     * POST /qurbani-settings/api/wa-auto/diagnose
     *
     * May-2026 — UI wrapper around the auto-WhatsApp worker so an
     * admin can answer "why isn't my slaughter / OFD message firing?"
     * without SSH or grepping laravel.log. Returns a structured
     * diagnostic snapshot — no message is sent, no state changes.
     *
     * Output sections:
     *   config       — current qurbani_wa_* values (master/slaughter/ofd
     *                  enabled flags, template names, delay/window
     *                  minutes, test phone). The most common cause of
     *                  "no message sent" is one of these flags being
     *                  off — flagged in `blockers[]` if so.
     *   templates    — whether the configured template names exist in
     *                  t_wa_templates AND in which language(s) Meta
     *                  has approved them. Mismatched language is the
     *                  next most common cause (the qurbani_performed
     *                  vs qurbani_start mixup from the May-2026 logs).
     *   worker       — last scheduler tick timestamp + whether the
     *                  Cache lock is currently held.
     *   slaughter    — eligible/recent line items + per-row diagnosis:
     *                  ready / waiting for delay / already sent / no
     *                  phone / dedupe-blocked. So an operator can
     *                  point at one Mudasser-style test order and see
     *                  exactly why it's not firing.
     *   ofd          — same shape as `slaughter` but for the dispatch
     *                  trigger (qurbani_dispatched_at).
     *   recent_logs  — last 20 rows of t_ops_qurbani_wa_log for the
     *                  current day so the operator can see what the
     *                  worker DID send + why anything was skipped.
     *
     * Stateless — does not run the worker, does not write any row.
     */
    public function diagnoseWaAuto(Request $request)
    {
        try {
            $cfg = $this->loadWaAutoConfig();

            // ── Section 1: blockers (high-signal config issues) ──────
            $blockers = [];
            if (!$cfg['master_enabled']) {
                $blockers[] = 'Master switch is OFF (qurbani_wa_auto_enabled). Nothing will fire.';
            }
            if ($cfg['slaughter_enabled'] && trim((string) $cfg['slaughter_template']) === '') {
                $blockers[] = 'Slaughter trigger is ON but no template is selected.';
            }
            if ($cfg['ofd_enabled'] && trim((string) $cfg['ofd_template']) === '' && trim((string) $cfg['ofd_self_collection_template']) === '') {
                $blockers[] = 'OFD trigger is ON but neither delivery nor self-collection template is selected.';
            }

            // ── Section 2: template existence + approved language(s) ─
            // The May-2026 "(#132001) Template name does not exist in
            // the translation" error comes from sendTemplateMessage()
            // being called with a language code Meta doesn't have
            // approved for the template. Our worker tries every
            // approved language + 4 fallback codes — but if NONE of
            // those work the operator needs to know which language to
            // approve in Meta, or rename their template to match. So
            // we surface the exact rows from t_wa_templates here.
            $templateNames = array_values(array_filter(array_unique([
                trim((string) $cfg['slaughter_template']),
                trim((string) $cfg['ofd_template']),
                trim((string) $cfg['ofd_self_collection_template']),
            ])));
            $templates = [];
            if (!empty($templateNames)) {
                $rows = DB::table('t_wa_templates')
                    ->whereIn('name', $templateNames)
                    ->select('name', 'language', 'status', 'category')
                    ->get();
                $byName = $rows->groupBy('name');
                foreach ($templateNames as $tn) {
                    $hits = $byName[$tn] ?? collect();
                    $approved = $hits->where('status', 'approved')->pluck('language')->values()->all();
                    $other    = $hits->where('status', '!=', 'approved')
                        ->map(fn($r) => $r->language . '(' . $r->status . ')')
                        ->values()->all();
                    $templates[] = [
                        'name'              => $tn,
                        'approved_langs'    => $approved,
                        'other_status'      => $other,
                        'exists_in_db'      => $hits->isNotEmpty(),
                        'cached_lang'       => \Cache::get('qurbani_wa_lang:' . $tn),
                    ];
                    if ($hits->isEmpty()) {
                        $blockers[] = "Template '{$tn}' is not in t_wa_templates — worker will try fallback langs (en, en_US, ur, en_GB) but Meta will reject if none are approved.";
                    } elseif (empty($approved)) {
                        $blockers[] = "Template '{$tn}' exists but no language is approved in Meta. " . ($other ? 'Statuses: ' . implode(', ', $other) : '');
                    }
                }
            }

            // ── Section 3: worker / lock state ───────────────────────
            $lockHeld     = \Cache::has('qurbani_wa_auto_lock');
            $lockTs       = $lockHeld ? \Cache::get('qurbani_wa_auto_lock') : null;
            // May-2026 — heartbeat from processNow().
            //   last_entry_at — stamps on EVERY call (including
            //     master-off / locked). Tells you ANY of the three
            //     trigger sources has fired recently:
            //       (a) Laravel scheduler cron (everyMinute)
            //       (b) manager polling the Qurbani planner
            //       (c) rider GPS heartbeat (every 5 min per checked-
            //           in rider, May-2026 piggyback)
            //   last_tick_at — stamps only when the lock was acquired
            //     i.e. the worker actually ran end-to-end. Lets you
            //     distinguish "lock contention" from "worker dead".
            //
            // Stale check: warn only if there's been NO entry in the
            // last 8 minutes. The slowest trigger source is the rider
            // heartbeat (5 min), so 8 min = "even the slowest source
            // is down". For 2-min slaughter delays this is fine; for
            // sub-minute precision the cron is still preferred.
            $lastEntry    = \Cache::get('qurbani_wa_last_entry_at');
            $lastTick     = \Cache::get('qurbani_wa_last_tick_at');
            $entryAgoMin  = null;
            if ($lastEntry) {
                try {
                    $entryAgoMin = (int) \Carbon\Carbon::parse($lastEntry)->diffInMinutes(now());
                } catch (\Throwable $e) {}
            }
            $tickAgoMin = null;
            if ($lastTick) {
                try {
                    $tickAgoMin = (int) \Carbon\Carbon::parse($lastTick)->diffInMinutes(now());
                } catch (\Throwable $e) {}
            }
            $workerIsStale = $entryAgoMin === null || $entryAgoMin > 8;
            if ($workerIsStale) {
                $blockers[] = $lastEntry
                    ? "Worker hasn't been triggered in {$entryAgoMin} min. Trigger sources: (a) scheduler cron, (b) manager-planner poll, (c) rider GPS heartbeat. If no rider is checked in AND no manager is polling AND no cron, the worker won't fire. Press Send Now."
                    : 'Worker has never been triggered on this host. Either set up the scheduler cron `* * * * * cd /path && php artisan schedule:run`, or rely on the rider heartbeat / manager-planner triggers (active during Qurbani hours), or use Send Now manually.';
            }

            // ── Section 4: slaughter candidates (per-row diagnosis) ──
            // Pull every slaughtered-today line item, then for each
            // explain whether the worker would fire on it RIGHT NOW.
            // This is the answer to "I marked it slaughtered, why no
            // message?" — far cheaper than guessing from logs.
            $slaughter = $this->buildSlaughterDiagnosis($cfg);

            // ── Section 5: OFD candidates ────────────────────────────
            $ofd = $this->buildOfdDiagnosis($cfg);

            // ── Section 6: recent log rows ───────────────────────────
            $logsTableExists = \Illuminate\Support\Facades\Schema::hasTable('t_ops_qurbani_wa_log');
            $recentLogs = [];
            if ($logsTableExists) {
                $recentLogs = DB::table('t_ops_qurbani_wa_log as l')
                    ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                    ->select(
                        'l.id', 'l.line_item_id', 'l.order_id', 'l.trigger_event',
                        'l.template_name', 'l.wa_phone', 'l.status', 'l.skip_reason',
                        'l.delivery_time_used', 'l.created_at',
                        'o.order_number'
                    )
                    ->orderByDesc('l.id')
                    ->limit(20)
                    ->get()
                    ->map(fn($r) => (array) $r)
                    ->all();
            }

            return response()->json([
                'success'    => true,
                'now'        => now()->toDateTimeString(),
                'tz'         => config('app.timezone'),
                'blockers'   => $blockers,
                'config'     => $cfg,
                'templates'  => $templates,
                'worker'     => [
                    'lock_held'      => $lockHeld,
                    'lock_ts'        => $lockTs,
                    'last_entry_at'  => $lastEntry,
                    'last_entry_ago' => $entryAgoMin,
                    'last_tick_at'   => $lastTick,
                    'last_tick_ago'  => $tickAgoMin,
                    'note'           => 'Triggers: (1) scheduler cron everyMinute, (2) manager planner polling, (3) rider GPS heartbeat every 5 min/rider. Any of the three keeps the worker alive — combined with the 55s lock, no spam.',
                ],
                'slaughter'   => $slaughter,
                'ofd'         => $ofd,
                'recent_logs' => $recentLogs,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('diagnoseWaAuto failed', ['err' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Diagnose failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Per-slaughter-candidate diagnosis. Mirrors the eligibility
     * logic in QurbaniWaAutoSender::fetchSlaughterCandidates so the
     * output is faithful to what the worker actually checks.
     */
    private function buildSlaughterDiagnosis(array $cfg): array
    {
        $delayMin       = max(0, (int) $cfg['slaughter_delay_minutes']);
        $startCutoff    = now()->subHours(12)->toDateTimeString(); // wider lookback than worker so we surface "missed window" too
        $isTest         = trim((string) $cfg['test_phone']) !== '';
        // May-2026 — dedupe is ALL-TIME, matches the fix in
        // QurbaniWaAutoSender::isAlreadySent. Previously this diagnosis
        // applied a 2-min dedupe relaxation when test_phone was set,
        // which masked the same bug that caused the production spam
        // (the worker re-sending every ~2 min for 6 hours).

        $rows = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotNull('li.qurbani_slaughtered_at')
            ->where('li.qurbani_slaughtered_at', '>=', $startCutoff)
            ->select(
                'li.id as line_item_id',
                'li.qurbani_slaughtered_at',
                'li.qurbani_slot',
                'li.qurbani_delivery_type',
                'o.order_number',
                'o.address_phone',
                'c.phone as customer_phone',
                'o.address_first_name',
                'c.first_name as customer_first_name'
            )
            ->orderByDesc('li.qurbani_slaughtered_at')
            ->limit(30)
            ->get();

        $diagnosed = [];
        foreach ($rows as $r) {
            $slaughteredAt = \Carbon\Carbon::parse($r->qurbani_slaughtered_at);
            $minutesSince  = (int) $slaughteredAt->diffInMinutes(now());
            $eligibleAt    = $slaughteredAt->copy()->addMinutes($delayMin);

            // Already-sent gate — mirrors QurbaniWaAutoSender::isAlreadySent
            // (all-time dedupe, no test-mode relaxation).
            $alreadySent = DB::table('t_ops_qurbani_wa_log')
                ->where('line_item_id', $r->line_item_id)
                ->where('trigger_event', 'slaughtered')
                ->where('status', 'sent')
                ->exists();
            $latestLog   = DB::table('t_ops_qurbani_wa_log')
                ->where('line_item_id', $r->line_item_id)
                ->where('trigger_event', 'slaughtered')
                ->orderByDesc('id')
                ->select('status', 'skip_reason', 'wa_phone', 'created_at')
                ->first();

            $hasPhone = trim((string) ($r->address_phone ?? '')) !== ''
                     || trim((string) ($r->customer_phone ?? '')) !== '';
            $effectivePhone = $hasPhone
                ? ($r->address_phone ?: $r->customer_phone)
                : ($isTest ? '(no_customer_phone → test_phone)' : null);

            if ($alreadySent) {
                $verdict = 'ALREADY_SENT';
                $reason = 'A sent log row exists for this line item — customer was already messaged. To re-test, use the Performance screen 🔪 button with force, or DELETE the log row.';
            } elseif (!$cfg['master_enabled']) {
                $verdict = 'BLOCKED'; $reason = 'Master switch is OFF.';
            } elseif (!$cfg['slaughter_enabled']) {
                $verdict = 'BLOCKED'; $reason = 'Slaughter trigger is OFF.';
            } elseif (trim((string) $cfg['slaughter_template']) === '') {
                $verdict = 'BLOCKED'; $reason = 'No slaughter template selected.';
            } elseif ($minutesSince < $delayMin) {
                $verdict = 'WAITING';
                $waitMore = $delayMin - $minutesSince;
                $reason = "Delay not reached yet ({$minutesSince}m/{$delayMin}m). Eligible at " . $eligibleAt->format('H:i:s') . " (in {$waitMore}m).";
            } elseif ($effectivePhone === null) {
                $verdict = 'NO_PHONE';
                $reason = 'No customer/address phone, and test_phone is empty → worker will log skipped:no_phone.';
            } else {
                $verdict = 'READY';
                $reason = "Eligible since " . $eligibleAt->format('H:i:s') . " — next worker tick will fire.";
            }

            $diagnosed[] = [
                'line_item_id'          => (int) $r->line_item_id,
                'order_number'          => (string) ($r->order_number ?? ''),
                'customer'              => trim(($r->address_first_name ?? '') ?: ($r->customer_first_name ?? '')),
                'slaughtered_at'        => (string) $r->qurbani_slaughtered_at,
                'minutes_since'         => $minutesSince,
                'eligible_at'           => $eligibleAt->toDateTimeString(),
                'effective_phone'       => (string) ($effectivePhone ?? ''),
                'verdict'               => $verdict,
                'reason'                => $reason,
                'latest_log'            => $latestLog ? (array) $latestLog : null,
            ];
        }

        return [
            'mode'         => $isTest ? 'test' : 'production',
            'delay_min'    => $delayMin,
            'lookback_h'   => 12,
            'candidates'   => $diagnosed,
        ];
    }

    /**
     * Per-OFD-candidate diagnosis. Mirrors fetchOfdCandidates + the
     * ETA-window gate inside processOfdTrigger.
     */
    private function buildOfdDiagnosis(array $cfg): array
    {
        $windowMin   = max(0, (int) $cfg['ofd_eta_window_minutes']);
        $isTest      = trim((string) $cfg['test_phone']) !== '';
        // May-2026 — dedupe is ALL-TIME, see fetchOfdCandidates fix.

        $rows = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotNull('li.qurbani_dispatched_at')
            ->whereNull('li.qurbani_delivered_at')
            ->where('li.qurbani_dispatched_at', '>=', now()->subHours(24)->toDateTimeString())
            ->select(
                'li.id as line_item_id',
                'li.qurbani_dispatched_at',
                'li.qurbani_estimated_delivery_at',
                'li.qurbani_slot',
                'li.qurbani_delivery_type',
                'o.order_number',
                'o.address_phone',
                'c.phone as customer_phone',
                'o.address_first_name',
                'c.first_name as customer_first_name'
            )
            ->orderByDesc('li.qurbani_dispatched_at')
            ->limit(30)
            ->get();

        $diagnosed = [];
        foreach ($rows as $r) {
            $isSelfCollect = false;
            $dt = strtolower((string) ($r->qurbani_delivery_type ?? ''));
            if (str_contains($dt, 'self') || str_contains($dt, 'collect') || str_contains($dt, 'pickup') || str_contains($dt, 'pick-up') || str_contains($dt, 'pick up')) {
                $isSelfCollect = true;
            }

            $alreadySent = DB::table('t_ops_qurbani_wa_log')
                ->where('line_item_id', $r->line_item_id)
                ->where('trigger_event', 'ofd')
                ->where('status', 'sent')
                ->exists();

            $template = $isSelfCollect ? $cfg['ofd_self_collection_template'] : $cfg['ofd_template'];
            $tplMissing = trim((string) $template) === '';

            $etaInfo = null; $minutesUntilEta = null;
            if (!empty($r->qurbani_estimated_delivery_at)) {
                $eta = \Carbon\Carbon::parse($r->qurbani_estimated_delivery_at);
                $minutesUntilEta = -1 * (int) $eta->diffInMinutes(now(), false);
                $etaInfo = $eta->format('H:i') . " (" . ($minutesUntilEta >= 0 ? "in {$minutesUntilEta}m" : (abs($minutesUntilEta) . "m ago")) . ")";
            }

            $hasPhone = trim((string) ($r->address_phone ?? '')) !== '' || trim((string) ($r->customer_phone ?? '')) !== '';
            $effectivePhone = $hasPhone ? ($r->address_phone ?: $r->customer_phone) : ($isTest ? '(no_customer_phone → test_phone)' : null);

            if ($alreadySent) {
                $verdict = 'ALREADY_SENT';
                $reason = 'Already sent. Dedupe blocks all resends — use Performance screen 🛵 button with force for re-tests.';
            } elseif (!$cfg['master_enabled']) {
                $verdict = 'BLOCKED'; $reason = 'Master switch is OFF.';
            } elseif (!$cfg['ofd_enabled']) {
                $verdict = 'BLOCKED'; $reason = 'OFD trigger is OFF.';
            } elseif ($tplMissing) {
                $verdict = 'BLOCKED';
                $reason = $isSelfCollect ? 'Self-collection template not set.' : 'Delivery template not set.';
            } elseif (!$isSelfCollect && $minutesUntilEta !== null && $minutesUntilEta > $windowMin) {
                $verdict = 'WAITING';
                $reason = "ETA is {$minutesUntilEta}m away — outside the {$windowMin}m window. Worker re-evaluates every minute.";
            } elseif ($effectivePhone === null) {
                $verdict = 'NO_PHONE'; $reason = 'No phone on file + test_phone empty.';
            } else {
                $verdict = 'READY';
                $reason = $isSelfCollect
                    ? 'Self-collection — fires immediately on next worker tick.'
                    : ($minutesUntilEta === null
                        ? 'No ETA — falls back to slot string, fires immediately.'
                        : "Within {$windowMin}m window — fires on next tick.");
            }

            $diagnosed[] = [
                'line_item_id'    => (int) $r->line_item_id,
                'order_number'    => (string) ($r->order_number ?? ''),
                'customer'        => trim(($r->address_first_name ?? '') ?: ($r->customer_first_name ?? '')),
                'dispatched_at'   => (string) $r->qurbani_dispatched_at,
                'eta'             => $etaInfo,
                'delivery_type'   => (string) ($r->qurbani_delivery_type ?? ''),
                'is_self_collect' => $isSelfCollect,
                'effective_phone' => (string) ($effectivePhone ?? ''),
                'verdict'         => $verdict,
                'reason'          => $reason,
            ];
        }

        return [
            'mode'         => $isTest ? 'test' : 'production',
            'window_min'   => $windowMin,
            'candidates'   => $diagnosed,
        ];
    }

    /**
     * POST /qurbani-settings/api/wa-auto/run-now
     *
     * May-2026 — UI trigger for the auto-WhatsApp worker so an admin
     * can fire a tick on demand instead of waiting for the next cron
     * minute (or for the manager-polling terminating() hook). Useful
     * in dev where the operator may not have `php artisan
     * schedule:work` running, and in prod for "I just enabled this,
     * run it now" workflows.
     *
     * Optional body: { release_lock?: bool }  — if true, deletes the
     *   qurbani_wa_auto_lock cache key before firing so the call
     *   succeeds even if a previous tick is still mid-process. Use
     *   carefully — back-to-back releases can cause concurrent sends.
     *
     * Returns processNow()'s output verbatim + the matching log rows
     * created during this tick so the operator can see exactly what
     * happened. No silent failures: every state (master_off, locked,
     * sent/skipped/failed counts) is surfaced.
     */
    public function runWaAutoNow(Request $request, \App\Services\QurbaniWaAutoSender $sender)
    {
        $releaseLock = (bool) $request->input('release_lock', false);

        try {
            if ($releaseLock) {
                \Cache::forget('qurbani_wa_auto_lock');
            }

            $beforeId = (int) (DB::table('t_ops_qurbani_wa_log')->max('id') ?? 0);
            $result   = $sender->processNow(20);
            $newRows  = DB::table('t_ops_qurbani_wa_log as l')
                ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->where('l.id', '>', $beforeId)
                ->select(
                    'l.id', 'l.line_item_id', 'l.trigger_event',
                    'l.template_name', 'l.wa_phone', 'l.status', 'l.skip_reason',
                    'l.created_at', 'o.order_number'
                )
                ->orderBy('l.id')
                ->get()
                ->map(fn($r) => (array) $r)
                ->all();

            \Illuminate\Support\Facades\Log::info('runWaAutoNow: manual tick', [
                'release_lock' => $releaseLock,
                'result'       => $result,
                'new_log_rows' => count($newRows),
            ]);

            return response()->json([
                'success'      => true,
                'result'       => $result,
                'new_log_rows' => $newRows,
                'note'         => $result['ran']
                    ? 'Worker ran. See new_log_rows for what was attempted.'
                    : 'Worker did not run: ' . ($result['reason'] ?? 'unknown') . '. If "locked", set release_lock and retry.',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('runWaAutoNow failed', ['err' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Run failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function reorderOptions(Request $request)
    {
        $validated = $request->validate([
            'field_name' => 'required|string',
            'order' => 'required|array',
            'order.*' => 'integer',
        ]);

        foreach ($validated['order'] as $position => $id) {
            DB::table('t_crm_qurbani_field_options')
                ->where('id', $id)
                ->where('field_name', $validated['field_name'])
                ->update(['display_order' => $position + 1, 'updated_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Order updated']);
    }

    public function updateHiddenCategories(Request $request)
    {
        $validated = $request->validate([
            'hidden' => 'present|array',
            'hidden.*' => 'string',
        ]);

        \App\Models\FIN\ConfigModel::set(
            'qurbani_hidden_stats_categories',
            json_encode($validated['hidden']),
            'Category level-2 names hidden from the booked summary stats table'
        );

        return response()->json([
            'success' => true,
            'message' => 'Stats category visibility updated',
        ]);
    }

    /**
     * POST /qurbani-settings/api/backfill-verified-coords
     *
     * May-2026 — UI wrapper around the qurbani:backfill-verified-coords
     * artisan command so a non-CLI admin can repair customer rows that
     * have a verified_location_url set but NULL lat/lng (the Waseem
     * Aslam case). Without this button the only way to clear out
     * historical short links was SSH + artisan, which isn't an option
     * on the shared production host.
     *
     * Request body:
     *   { dry_run?: bool,  // default false — preview without writes
     *     limit?:   int }  // default 200 — cap rows per click so the
     *                      // HTTP request stays under the PHP timeout.
     *                      // The button re-enables after each click so
     *                      // staff can chip away at larger backlogs.
     *
     * Returns the same structured counts the artisan command shows.
     */
    public function backfillVerifiedCoords(Request $request, \App\Services\QurbaniVerifiedCoordsBackfill $svc)
    {
        $validated = $request->validate([
            'dry_run' => 'nullable|boolean',
            // Hard ceiling of 1000 per click — keeps the request well
            // inside the default 30s PHP timeout even on a slow uplink
            // (each row does one cURL roundtrip with 8s timeout).
            'limit'   => 'nullable|integer|min:1|max:1000',
        ]);

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $limit  = (int)  ($validated['limit']   ?? 200);

        try {
            $result = $svc->run($limit, $dryRun);

            return response()->json([
                'success' => true,
                'result'  => $result,
                'message' => $dryRun
                    ? "Dry run: would have fixed {$result['fixed']} of {$result['processed']} processed customer(s) (out of {$result['candidates']} candidates)."
                    : "Fixed {$result['fixed']} of {$result['processed']} processed customer(s). {$result['candidates']} candidate row(s) total — re-click to continue if more remain.",
            ]);
        } catch (\Throwable $e) {
            \Log::error('Qurbani backfill-verified-coords (UI) failed', [
                'user_id' => auth()->id(),
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Backfill failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /qurbani-settings/api/qurbani-riders
     *
     * May-2026 — Save the whitelist of user IDs that show up in the
     * mobile Qurbani rider pickers (individual order detail picker AND
     * the bulk-assign modal both read from this same config). Empty
     * array = show every active rider (default behaviour).
     *
     * We only persist user IDs that actually resolve to an active user
     * record — keeps stale IDs from sneaking in if someone hand-edits
     * the request payload and a rider has since been deactivated.
     */
    public function updateQurbaniRiders(Request $request)
    {
        $validated = $request->validate([
            'rider_ids'   => 'present|array',
            'rider_ids.*' => 'integer|min:1',
        ]);

        $rawIds = array_values(array_unique(array_map('intval', $validated['rider_ids'])));

        // Resolve against t_sys_user so we don't store IDs that no
        // longer correspond to an active rider account.
        $valid = [];
        if (!empty($rawIds)) {
            $valid = DB::table('t_sys_user')
                ->whereIn('id', $rawIds)
                ->where('is_active', 1)
                ->pluck('id')
                ->map(fn($v) => (int) $v)
                ->values()
                ->all();
        }

        \App\Models\FIN\ConfigModel::set(
            'qurbani_rider_ids',
            json_encode($valid),
            'Whitelist of user IDs shown in mobile Qurbani rider pickers (empty = all active)'
        );

        return response()->json([
            'success'    => true,
            'message'    => 'Qurbani rider list updated',
            'rider_ids'  => $valid,
            'dropped'    => array_values(array_diff($rawIds, $valid)),
        ]);
    }

    /**
     * POST /qurbani-settings/api/qurbani-rider-meta
     *
     * May-2026 — Saves per-rider extras (region + contact phone) for
     * the Qurbani rider whitelist. Stored in a SEPARATE config key
     * (`qurbani_rider_meta`) from the existing `qurbani_rider_ids`
     * whitelist so:
     *   • Older code paths that only care about the int[] whitelist
     *     keep working unchanged (zero blast radius for the existing
     *     mobile / dispatch / WhatsApp consumers).
     *   • Removing a rider from the whitelist does NOT erase their
     *     meta — re-adding them later restores the saved region /
     *     contact rather than forcing a re-type.
     *
     * Shape on disk (JSON):
     *   { "<user_id>": { "region": "...", "contact": "+92..." }, ... }
     *
     * Request body:
     *   { rider_id: int,
     *     region:   string|null  (max 80 chars, must match a
     *                             t_crm_qurbani_field_options
     *                             qurbani_region option_value when
     *                             non-empty),
     *     contact:  string|null  (max 32 chars, light shape check) }
     *
     * Why one rider per POST instead of a bulk replace? The settings
     * UI debounces each text-input change individually — sending the
     * whole map on every keystroke would race with the next keystroke
     * if two riders are edited in quick succession. Per-rider posts
     * merge cleanly on the server.
     */
    public function updateQurbaniRiderMeta(Request $request)
    {
        $validated = $request->validate([
            'rider_id' => 'required|integer|min:1',
            'region'   => 'nullable|string|max:80',
            'contact'  => 'nullable|string|max:32',
        ]);

        $riderId = (int) $validated['rider_id'];
        $region  = isset($validated['region'])  ? trim((string) $validated['region'])  : '';
        $contact = isset($validated['contact']) ? trim((string) $validated['contact']) : '';

        // Reject unknown rider IDs (defence-in-depth — the UI only
        // exposes existing users but a hand-crafted POST should not
        // be able to plant meta for arbitrary IDs).
        $userExists = DB::table('t_sys_user')->where('id', $riderId)->exists();
        if (!$userExists) {
            return response()->json([
                'success' => false,
                'message' => 'Rider not found',
            ], 422);
        }

        // Validate region against the live qurbani_region options
        // (same source the mobile and CRM dropdowns use). An empty
        // region is allowed — it clears the existing assignment.
        if ($region !== '') {
            $regionExists = DB::table('t_crm_qurbani_field_options')
                ->where('field_name', 'qurbani_region')
                ->where('is_active', 1)
                ->where('option_value', $region)
                ->exists();
            if (!$regionExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Region "' . $region . '" is not a valid qurbani region option',
                ], 422);
            }
        }

        // Light contact-number shape check. We don't enforce a strict
        // E.164 because the field is informational (the OFD template
        // phase will format it) — just trip on obviously bad inputs.
        if ($contact !== '' && !preg_match('/^[\+0-9 ()\-]{6,}$/', $contact)) {
            return response()->json([
                'success' => false,
                'message' => 'Contact number contains invalid characters',
            ], 422);
        }

        // Load existing map, merge, save.
        $raw = (string) \App\Models\FIN\ConfigModel::get('qurbani_rider_meta', '{}');
        $map = json_decode($raw, true);
        if (!is_array($map)) { $map = []; }

        if ($region === '' && $contact === '') {
            // Both empty — drop the entry entirely so the map stays
            // compact (vs storing {"region":"","contact":""}).
            unset($map[(string) $riderId]);
        } else {
            $map[(string) $riderId] = [
                'region'  => $region,
                'contact' => $contact,
            ];
        }

        \App\Models\FIN\ConfigModel::set(
            'qurbani_rider_meta',
            json_encode($map, JSON_UNESCAPED_UNICODE),
            'Per-Qurbani-rider metadata (region + contact). Region used to sort/group the rider picker in the mobile QurbaniOpenOrdersScreen; contact reserved for the OFD WhatsApp template (next phase).'
        );

        return response()->json([
            'success' => true,
            'message' => 'Rider details saved',
            'rider_id' => $riderId,
            'meta'    => $map[(string) $riderId] ?? null,
        ]);
    }
}
