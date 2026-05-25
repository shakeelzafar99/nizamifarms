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
}
