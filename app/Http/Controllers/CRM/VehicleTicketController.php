<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\VehicleTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 🛠 BIKE TICKETS — the one door, for the web and the phone alike.
 *
 * ⭐ EVERY RULE LIVES IN VehicleTicketService. This class only does what a controller
 *   should: validate the shape of what arrived, store an upload, hand it to the service,
 *   fire the push, and turn the answer into JSON. The web routes and the mobile routes
 *   call the SAME methods (the `api*` wrappers merely flip `$mobileContext`, exactly as
 *   FleetFuelController::apiMarkServiced does), so a rule can never be enforced on one
 *   surface and not the other — the failure this whole round started from.
 *
 * ⚠ NO PERMISSION MIDDLEWARE on these routes, deliberately. A rider has no fleet access
 *   at all and must still be able to report a fault on the bike he is holding. The
 *   audience rule is inside the service (`mayRead` / `canManage`), the same way
 *   BikeServiceAlerts::forUser owns its own audience rather than leaning on a route gate.
 */
class VehicleTicketController extends Controller
{
    /** Photos and voice notes, in KB — matched to the existing vehicle-photo and
     *  WhatsApp voice-note caps so one attachment path behaves like the others. */
    private const MAX_MEDIA_KB = 8192;

    private bool $mobileContext = false;

    /**
     * ⚠ How many uploads the last attachUploads() call could NOT store. Returned to the
     *   client as `attachments_failed` so a dropped voice note is SHOWN, not swallowed —
     *   the real-HTTP smoke test caught a ticket that came back "success" with its voice
     *   note silently missing, and a rider has no other way to know.
     */
    private int $lastFailed = 0;

    public function __construct(private VehicleTicketService $tickets)
    {
    }

    // ── mobile entries — same methods, mobile permission tables ──────────────────
    public function apiIndex(Request $r)            { $this->mobileContext = true; return $this->index($r); }
    public function apiShow(Request $r, $id)        { $this->mobileContext = true; return $this->show($r, $id); }
    public function apiStore(Request $r)            { $this->mobileContext = true; return $this->store($r); }
    public function apiReply(Request $r, $id)       { $this->mobileContext = true; return $this->reply($r, $id); }
    public function apiClose(Request $r, $id)       { $this->mobileContext = true; return $this->close($r, $id); }
    public function apiReopen(Request $r, $id)      { $this->mobileContext = true; return $this->reopen($r, $id); }
    public function apiMarkRead(Request $r, $id)    { $this->mobileContext = true; return $this->markRead($r, $id); }
    public function apiAlerts(Request $r)           { $this->mobileContext = true; return $this->alerts($r); }

    // ─────────────────────────────────────────────────────────────────────────────

    /** The list this user may see. `vehicle_id` narrows it to one machine. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => 'nullable|integer',
            'user_id'    => 'nullable|integer',
            'status'     => 'nullable|in:open,closed,all',
            'limit'      => 'nullable|integer|min:1|max:200',
        ]);

        $user = $request->user() ?: auth()->user();
        return response()->json([
            'success'    => true,
            'available'  => $this->tickets->available(),
            'can_manage' => $this->tickets->canManage($user, $this->mobileContext),
            'tickets'    => $this->tickets->listFor($user, $data, $this->mobileContext),
            // ⚠ His OWN machines, so the app can ask WHICH when he holds more than one —
            //   a rider driving the van still owns his bike, and without this he could not
            //   report a fault on either. Empty for a manager: he picks a rider instead.
            'my_machines' => $user ? $this->tickets->myMachines((int) $user->id) : [],
        ]);
    }

    /** One ticket with its thread. Reading it marks it read for this person. */
    public function show(Request $request, $id)
    {
        $user   = $request->user() ?: auth()->user();
        $ticket = $this->tickets->find((int) $id);
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'That ticket no longer exists.'], 404);
        }
        if (!$this->tickets->mayRead($user, $ticket, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => 'You cannot see that ticket.'], 403);
        }

        // Shape it through the same path the list uses, so one ticket and a row in the
        // list can never describe themselves differently.
        $shaped = collect($this->tickets->listFor($user, ['vehicle_id' => (int) $ticket['vehicle_id'], 'status' => 'all', 'limit' => 200], $this->mobileContext))
            ->firstWhere('id', (int) $id);

        $thread = $this->tickets->thread((int) $id);
        $this->tickets->markRead((int) $user->id, (int) $id);

        return response()->json([
            'success'    => true,
            'ticket'     => $shaped,
            'messages'   => $thread,
            'can_manage' => $this->tickets->canManage($user, $this->mobileContext),
            'can_close'  => $this->tickets->canManage($user, $this->mobileContext)
                            && ($shaped['is_open'] ?? false),
        ]);
    }

    /** Raise one. A rider may omit vehicle_id — the registry answers it. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id'         => 'nullable|integer',
            'opened_for_user_id' => 'nullable|integer',
            'category'           => 'nullable|in:problem,service,accident,other',
            'urgent'             => 'nullable|boolean',
            'title'              => 'required|string|max:120',
            'body'               => 'nullable|string|max:2000',
        ]);

        $user = $request->user() ?: auth()->user();
        $res  = $this->tickets->open($user, $data, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }

        // Photos may ride along with the opening message — a fault is far easier to
        // show than to describe, and making the rider open the ticket and THEN attach
        // is a second step he will skip.
        $attached = $this->attachUploads($request, $user, (int) $res['ticket_id']);

        $this->notify('opened', (int) $res['ticket_id'], $user);

        return response()->json([
            'success'             => true,
            'ticket_id'           => (int) $res['ticket_id'],
            'attachments_failed'  => $this->lastFailed,
            'message'             => $res['message'] . ($attached ? ' ' . $attached : ''),
        ]);
    }

    /**
     * Add a message. Accepts a plain text body, and/or photos, and/or one voice note —
     * so the phone can send a recording with a caption in one request.
     */
    public function reply(Request $request, $id)
    {
        $request->validate([
            'body'        => 'nullable|string|max:2000',
            'photos'      => 'nullable|array|max:8',
            'photos.*'    => 'image|max:' . self::MAX_MEDIA_KB,
            'audio'       => 'nullable|file|max:' . self::MAX_MEDIA_KB,
            'duration_ms' => 'nullable|integer|min:0|max:360000',
        ]);

        $user = $request->user() ?: auth()->user();
        $body = trim((string) $request->input('body', ''));
        $hasMedia = $request->hasFile('photos') || $request->hasFile('audio');

        if ($body === '' && !$hasMedia) {
            return response()->json(['success' => false, 'message' => 'Nothing to send.'], 422);
        }

        $sentAny  = false;
        $reopened = false;
        $status   = null;

        // A caption goes first so it reads above its own attachment.
        if ($body !== '') {
            $res = $this->tickets->reply($user, (int) $id, ['kind' => 'text', 'body' => $body], $this->mobileContext);
            if (!$res['ok']) {
                return response()->json(['success' => false, 'message' => $res['message']], 422);
            }
            $sentAny  = true;
            $reopened = $reopened || !empty($res['reopened']);
            $status   = $res['status'] ?? $status;
        }

        $attached = $this->attachUploads($request, $user, (int) $id, $reopened || $sentAny, $reopened, $status);
        if (!$sentAny && $attached === '') {
            // Every attachment failed and there was no text — say so rather than
            // claiming success on an empty message.
            return response()->json(['success' => false, 'message' => 'The attachment could not be uploaded.'], 422);
        }

        $this->notify('replied', (int) $id, $user);

        return response()->json([
            'success'            => true,
            'reopened'           => $reopened,
            'status'             => $status,
            'attachments_failed' => $this->lastFailed,
            'message'            => trim(($reopened ? 'Re-opened. ' : '') . 'Sent. ' . $attached),
        ]);
    }

    public function close(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:500']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->tickets->close($user, (int) $id, $request->input('note'), $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('closed', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    public function reopen(Request $request, $id)
    {
        $user = $request->user() ?: auth()->user();
        $res  = $this->tickets->reopen($user, (int) $id, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('replied', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    public function markRead(Request $request, $id)
    {
        $user   = $request->user() ?: auth()->user();
        $ticket = $this->tickets->find((int) $id);
        if ($ticket && $this->tickets->mayRead($user, $ticket, $this->mobileContext)) {
            $this->tickets->markRead((int) $user->id, (int) $id);
        }
        return response()->json(['success' => true]);
    }

    /** Drives both banners (web corner card, mobile floating bar). Polled — keep cheap. */
    public function alerts(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        return response()->json(['success' => true]
            + $this->tickets->summaryFor($user, $this->mobileContext));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  uploads
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Store any photos and/or one voice note on the request as thread messages.
     * Returns a sentence for the caller to append. NEVER throws — a ticket that was
     * raised must not be reported as failed because a JPEG did not save (the same rule
     * VehicleController::storePhotos follows).
     */
    private function attachUploads(Request $request, $user, int $ticketId,
                                   bool $alreadySent = false, bool &$reopened = false, &$status = null): string
    {
        $saved = 0; $failed = 0;
        $this->lastFailed = 0;

        foreach ((array) $request->file('photos', []) as $file) {
            if (!$file || !$file->isValid()) { $failed++; continue; }
            $path = $this->storeOne($file, $ticketId, 'photo');
            if (!$path) { $failed++; continue; }
            $res = $this->tickets->reply($user, $ticketId, [
                'kind' => 'photo', 'media_path' => $path,
                'media_mime' => (string) $file->getMimeType(),
            ], $this->mobileContext);
            if (!empty($res['ok'])) {
                $saved++;
                $reopened = $reopened || !empty($res['reopened']);
                $status   = $res['status'] ?? $status;
            } else {
                $failed++;
                $this->deleteQuietly($path);
            }
        }

        if ($request->hasFile('audio')) {
            $file = $request->file('audio');
            // ⚠ The MIME is read from the BYTES, not the client label — Android tags the
            //   same AAC stream half a dozen ways, and a renamed .exe must not land in
            //   the media folder. Same allow-list as WhatsAppController::sendVoiceNote.
            $detected = $file && $file->isValid() ? strtolower((string) $file->getMimeType()) : '';
            $allowed  = ['audio/mp4', 'audio/aac', 'audio/x-m4a', 'audio/m4a', 'audio/mpeg',
                         'audio/mp3', 'audio/ogg', 'audio/opus', 'audio/3gpp', 'audio/amr',
                         'video/mp4',  // Android often reports .m4a as video/mp4
                         // ⚠ A raw AAC stream (ADTS, what the Assistant records) is sniffed as
                         //   this, not audio/aac. Found by the real-HTTP smoke test, which
                         //   uploaded one and got a "success" with the note silently missing.
                         'audio/x-hx-aac-adts', 'audio/vnd.dlna.adts'];
            if (!in_array($detected, $allowed, true)) {
                $failed++;
            } else {
                $path = $this->storeOne($file, $ticketId, 'voice');
                if (!$path) {
                    $failed++;
                } else {
                    $res = $this->tickets->reply($user, $ticketId, [
                        'kind' => 'voice', 'media_path' => $path, 'media_mime' => $detected,
                        'duration_ms' => $request->input('duration_ms'),
                    ], $this->mobileContext);
                    if (!empty($res['ok'])) {
                        $saved++;
                        $reopened = $reopened || !empty($res['reopened']);
                        $status   = $res['status'] ?? $status;
                    } else {
                        $failed++;
                        $this->deleteQuietly($path);
                    }
                }
            }
        }

        $this->lastFailed = $failed;
        if ($saved && !$failed) return $saved === 1 ? '' : "{$saved} attachments added.";
        if ($saved && $failed)  return "{$saved} attached, {$failed} could not be uploaded.";
        if ($failed)            return $alreadySent
            ? 'The attachment could not be uploaded — your message was sent.'
            : '';
        return '';
    }

    /**
     * ⚠ Streamed with putFileAs, never file_get_contents — eight 8 MB photos read into
     *   strings would blow the memory limit on one request (the lesson from the vehicle
     *   condition-photo batch upload).
     */
    private function storeOne($file, int $ticketId, string $kind): ?string
    {
        try {
            $dir  = 'vehicle-tickets/' . $ticketId;
            $ext  = strtolower($file->getClientOriginalExtension() ?: '');
            $safe = $kind === 'voice'
                ? ['m4a', 'mp4', 'aac', 'mp3', 'ogg', 'opus', '3gp', 'amr']
                : ['jpg', 'jpeg', 'png', 'webp', 'heic'];
            if (!in_array($ext, $safe, true)) $ext = $kind === 'voice' ? 'm4a' : 'jpg';
            $name = $kind . '_' . now()->format('Ymd_His') . '_' . uniqid() . '.' . $ext;
            Storage::disk('public')->putFileAs($dir, $file, $name);
            return $dir . '/' . $name;
        } catch (\Throwable $e) {
            Log::warning('Ticket media upload failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function deleteQuietly(?string $path): void
    {
        if (!$path) return;
        try { Storage::disk('public')->delete($path); } catch (\Throwable $e) { /* orphan, not a failure */ }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Tell the people who need to know. Best-effort: a push that does not send must
     * never fail the action, and the in-app banner will show it on the next poll anyway.
     */
    private function notify(string $event, int $ticketId, $actor): void
    {
        try {
            app(\App\Services\FirebaseService::class)
                ->notifyVehicleTicket($event, $ticketId, (int) ($actor->id ?? 0));
        } catch (\Throwable $e) {
            Log::warning('Ticket push failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
        }
    }
}
