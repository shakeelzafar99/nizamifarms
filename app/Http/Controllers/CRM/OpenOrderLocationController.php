<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Location\OpenOrderLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Open Orders → "Get Customer Locations".
 *
 * Stateless: every request recomputes from the live open list + WhatsApp log.
 */
class OpenOrderLocationController extends Controller
{
    public function __construct(protected OpenOrderLocationService $service)
    {
    }

    /** Live snapshot for the modal (eligible customers + recent wins + counts). */
    public function eligible(Request $request)
    {
        if (!config('open_order_location.enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Feature disabled'], 404);
        }

        // Pressing "Get Locations" also flushes any queued auto-requests off this
        // request (no-op when the automation is off; lock-shared with the cron),
        // so the stats below reflect a fresh drain.
        $this->service->fireFromRequest();

        $snapshot = $this->service->snapshot();

        return response()->json([
            'success'         => true,
            'needs'           => $snapshot['needs'],
            'resolved_recent' => $snapshot['resolved_recent'],
            'counts'          => $snapshot['counts'],
            // DB-backed global default (falls back to config file when unset).
            'default_template' => $this->service->defaultTemplate(),
            'default_language' => $this->service->defaultLanguage(),
            // When false, the mobile app shows the dropdown and the first send is
            // adopted as the default; when true, the dropdown is hidden by default.
            'default_is_set'   => $this->service->defaultTemplateIsSet(),
            // Daily auto-send stats (sent / responded / didn't-respond list).
            'auto_stats'      => $this->service->autoSendStats(),
            'chunk_size'      => (int) config('open_order_location.chunk_size', 20),
        ]);
    }

    /** Send the location-request template to one chunk of selected customers. */
    public function send(Request $request)
    {
        if (!config('open_order_location.enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Feature disabled'], 404);
        }

        $validated = $request->validate([
            'customer_ids'   => 'required|array|min:1|max:200',
            'customer_ids.*' => 'integer',
            'template_name'  => 'required|string|max:100',
            'language'       => 'nullable|string|max:10',
        ]);

        // Guard: only allow templates the app actually knows about.
        $exists = DB::table('t_wa_templates')->where('name', $validated['template_name'])->exists();
        if (!$exists) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown WhatsApp template: ' . $validated['template_name'],
            ], 422);
        }

        $out = $this->service->sendBulk(
            $validated['customer_ids'],
            $validated['template_name'],
            $validated['language'] ?? null,
            auth()->id()
        );

        // Auto-adopt the chosen template as the global default if none is set yet.
        $this->service->adoptDefaultTemplateIfUnset(
            $validated['template_name'],
            $validated['language'] ?? null
        );

        return response()->json([
            'success' => true,
            'results' => $out['results'],
            'sent'    => $out['sent'],
            'failed'  => $out['failed'],
            'skipped' => $out['skipped'],
        ]);
    }

    /** Current automation/default settings (for the web admin panel). */
    public function settings(Request $request)
    {
        return response()->json([
            'success'           => true,
            'default_template'  => $this->service->defaultTemplate(),
            'default_language'  => $this->service->defaultLanguage(),
            'default_is_set'    => $this->service->defaultTemplateIsSet(),
            'auto_enabled'      => $this->service->isAutoEnabled(),
            'window_start'      => (string) \App\Models\FIN\ConfigModel::get('location_auto_window_start', '09:00'),
            'window_end'        => (string) \App\Models\FIN\ConfigModel::get('location_auto_window_end', '18:00'),
        ]);
    }

    /**
     * Update automation/default settings from the web (admin).
     * All fields optional — only the provided ones are written.
     */
    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            'default_template' => 'nullable|string|max:100',
            'default_language' => 'nullable|string|max:10',
            'auto_enabled'     => 'nullable|boolean',
            'window_start'     => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'window_end'       => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ]);

        if ($request->has('default_template')) {
            $tpl = trim((string) $validated['default_template']);
            if ($tpl !== '') {
                $exists = DB::table('t_wa_templates')->where('name', $tpl)->exists();
                if (!$exists) {
                    return response()->json(['success' => false, 'message' => 'Unknown WhatsApp template: ' . $tpl], 422);
                }
            }
            // Set the default for everyone (empty string clears it back to the file fallback).
            \App\Models\FIN\ConfigModel::set('open_order_location_default_template', $tpl,
                'Global default WhatsApp template for Open Orders location requests.');
        }

        if ($request->filled('default_language')) {
            \App\Models\FIN\ConfigModel::set('open_order_location_default_lang', trim((string) $validated['default_language']));
        }

        if ($request->has('auto_enabled')) {
            \App\Models\FIN\ConfigModel::set('location_auto_send_enabled', $request->boolean('auto_enabled') ? '1' : '0',
                'Auto-send the location request when a Shopify order is accepted or a new NF order is created.');
        }

        if ($request->filled('window_start')) {
            \App\Models\FIN\ConfigModel::set('location_auto_window_start', $validated['window_start']);
        }
        if ($request->filled('window_end')) {
            \App\Models\FIN\ConfigModel::set('location_auto_window_end', $validated['window_end']);
        }

        return response()->json([
            'success'          => true,
            'message'          => 'Location settings saved.',
            'default_template' => $this->service->defaultTemplate(),
            'default_is_set'   => $this->service->defaultTemplateIsSet(),
            'auto_enabled'     => $this->service->isAutoEnabled(),
            'window_start'     => (string) \App\Models\FIN\ConfigModel::get('location_auto_window_start', '09:00'),
            'window_end'       => (string) \App\Models\FIN\ConfigModel::get('location_auto_window_end', '18:00'),
        ]);
    }
}
