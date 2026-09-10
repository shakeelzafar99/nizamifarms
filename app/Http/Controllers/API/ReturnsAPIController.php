<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\CRM\OrderReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RETURNS — the STORE half, on the phone.
 *
 * The manager takes the return on the web; the money is settled there and then.
 * What reaches here is the physical half: "these packets came back — put them
 * where they belong". Store staff scan each one at the shelf, which is the only
 * evidence the goods really returned.
 *
 * ⭐ Why the whole screen hangs off a permission the server checks on EVERY call
 * rather than a flag the app caches at login: the banner must appear for whoever
 * is on shift now. A 403 simply means no banner (the Overnight rule — one
 * authority, and it never nags the wrong person).
 */
class ReturnsAPIController extends Controller
{
    public function __construct(private OrderReturnService $returns)
    {
    }

    private function guard(): ?JsonResponse
    {
        $user = auth()->user();

        if (!$user || !$user->hasMobilePermission('scan_returns')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to put returned stock back.',
            ], 403);
        }

        return null;
    }

    /** Drives the banner. Empty list ⇒ the banner draws nothing. */
    public function pending(Request $request): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        return response()->json([
            'success' => true,
            'returns' => $this->returns->pendingPutBacks((int) $request->input('limit', 20)),
        ]);
    }

    /** One return: the lines, how much of each is back, and where each goes. */
    public function detail(Request $request, $returnId): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        try {
            return response()->json([
                'success' => true,
                'detail'  => $this->returns->putBackDetail((int) $returnId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * One packet, one scan.
     *
     * The barcode is re-decoded on the server and the server's reading wins —
     * the phone reports what it saw, it does not get to assert it. A PLU that is
     * not on the order is refused, which is the entire reason this is a scan and
     * not a tick.
     */
    public function scan(Request $request, $returnId): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        $validated = $request->validate([
            'barcode'     => 'required|string|max:32',
            'destination' => 'nullable|in:freezer,chiller',
        ]);

        try {
            return response()->json(array_merge(
                ['success' => true],
                $this->returns->scanBack(
                    (int) $returnId,
                    $validated['barcode'],
                    $validated['destination'] ?? null,
                    (int) auth()->id()
                )
            ));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Put a line back without a scan.
     *
     * Not a loophole — 95 of the 448 products carry no Czerlop PLU and physically
     * cannot be scale-scanned. Refusing them would strand the return and leave a
     * banner nobody could clear. Overnight allows the same manual route, and the
     * row records who did it either way.
     */
    public function manual(Request $request, $returnId): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        $validated = $request->validate([
            'line_item_id' => 'required|integer',
            'quantity'     => 'nullable|numeric|min:0.001|max:9999',
            'destination'  => 'nullable|in:freezer,chiller',
        ]);

        try {
            return response()->json(array_merge(
                ['success' => true],
                $this->returns->markBackManually(
                    (int) $returnId,
                    (int) $validated['line_item_id'],
                    isset($validated['quantity']) ? (float) $validated['quantity'] : null,
                    $validated['destination'] ?? null,
                    (int) auth()->id()
                )
            ));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Finish. "Short" needs a reason and is recorded as short — a packet that
     * never came back is a fact, and making the store fake a scan to clear a
     * banner would be the worse outcome.
     */
    public function complete(Request $request, $returnId): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        $validated = $request->validate([
            'short'  => 'nullable|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            return response()->json(array_merge(
                ['success' => true],
                $this->returns->complete(
                    (int) $returnId,
                    (int) auth()->id(),
                    (bool) ($validated['short'] ?? false),
                    $validated['reason'] ?? null
                )
            ));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
