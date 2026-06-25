<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\LineItemQuickNoteModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Manages the shared, team-wide list of per-line-item "quick note" presets.
 *
 * The SAME methods back both the web Edit Order screen (session auth, routes
 * in web.php) and the mobile order-edit screens (Sanctum auth, routes in
 * api.php) so there is a single source of truth — no duplicated logic.
 *
 * This controller never touches order line items or the `instructions` field
 * itself; it only reads/writes the preset list in t_crm_line_item_quick_note.
 */
class LineItemQuickNoteController extends Controller
{
    /**
     * List active quick-note presets, in display order.
     */
    public function index()
    {
        $notes = LineItemQuickNoteModel::where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'note_text', 'sort_order']);

        return response()->json([
            'success' => true,
            'data'    => $notes,
        ]);
    }

    /**
     * Pin a new quick note. Idempotent on the text: if the same note already
     * exists it is reused (and re-activated if it had been unpinned), so we
     * never create duplicate chips.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'note_text' => 'required|string|max:100',
        ]);

        $text = trim($validated['note_text']);
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Quick note text cannot be empty',
            ], 422);
        }

        // Reuse an existing row with the same text (case-insensitive match via
        // the column's collation) instead of creating a duplicate.
        $existing = LineItemQuickNoteModel::where('note_text', $text)->first();
        if ($existing) {
            if (!$existing->is_active) {
                $existing->is_active = 1;
                $existing->save();
            }
            return response()->json([
                'success'  => true,
                'data'     => $existing->only(['id', 'note_text', 'sort_order']),
                'reused'   => true,
            ]);
        }

        $nextSort = (int) LineItemQuickNoteModel::max('sort_order') + 1;

        $note = LineItemQuickNoteModel::create([
            'note_text'  => $text,
            'sort_order' => $nextSort,
            'is_active'  => 1,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $note->only(['id', 'note_text', 'sort_order']),
        ]);
    }

    /**
     * Unpin (soft-remove) a quick note. We deactivate rather than hard-delete
     * so the row's history/sort position is preserved and re-pinning the same
     * text simply reactivates it.
     */
    public function destroy($id)
    {
        $note = LineItemQuickNoteModel::find($id);
        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Quick note not found',
            ], 404);
        }

        $note->is_active = 0;
        $note->save();

        return response()->json([
            'success' => true,
            'message' => 'Quick note removed',
        ]);
    }
}
