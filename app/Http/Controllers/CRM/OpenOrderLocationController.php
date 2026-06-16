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

        $snapshot = $this->service->snapshot();

        return response()->json([
            'success'         => true,
            'needs'           => $snapshot['needs'],
            'resolved_recent' => $snapshot['resolved_recent'],
            'counts'          => $snapshot['counts'],
            'default_template' => config('open_order_location.default_template', 'location'),
            'default_language' => config('open_order_location.default_language', 'en'),
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

        return response()->json([
            'success' => true,
            'results' => $out['results'],
            'sent'    => $out['sent'],
            'failed'  => $out['failed'],
            'skipped' => $out['skipped'],
        ]);
    }
}
