<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * NF Assistant — settings (Phase 1 of NF-MESSAGES-ENHANCEMENTS-PLAN-JUL2026).
 *
 * The Settings sheet (mockup screen 3): what was previously teachable only in
 * chat is now visible AND editable in the UI. Three groups:
 *   • Defaults  — expense/vendor payment source, my online bank (t_ai_user_prefs)
 *   • Senders   — SMS sender → one of our banks (t_ai_sms_senders)
 *   • Forwarders— numbers whose WhatsApp forwards are payment proofs (t_ai_trusted_forwarders)
 *
 * Defaults reuse the SAME resolution as chat (AssistantToolRegistry::set_default),
 * so a value set here and a value taught by voice can never disagree. All
 * methods are gated on the `use_ai_assistant` mobile permission.
 */
class AssistantSettingsController extends Controller
{
    public function __construct(private AssistantToolRegistry $tools)
    {
    }

    /** Which get_context option list backs each preference key. */
    private const PREF_SOURCE = [
        'expense_payment_source_account_id'  => 'payment_source_accounts',
        'vendor_payment_source_account_id'   => 'payment_source_accounts',
        'expense_business_unit_id'           => 'business_units',
        'expense_receiving_account_id'       => 'banks',
        'vendor_payment_receiving_account_id' => 'banks',
    ];

    /** GET /api/assistant/settings — defaults (resolved to names) + option lists + senders + forwarders. */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        // Reuse the chat context tool — same accounts/banks/BUs the model sees,
        // and the raw saved-default ids.
        $ctx = $this->tools->call('get_context', [], $user);
        $options = [
            'payment_source_accounts' => $ctx['payment_source_accounts'] ?? [],
            'banks'                   => $ctx['banks'] ?? [],
            'business_units'          => $ctx['business_units'] ?? [],
        ];
        $savedIds = $ctx['saved_defaults'] ?? [];

        // Resolve each saved default id to a name from the matching option list,
        // so the UI shows "Expense Fund", not "5".
        $defaults = [];
        foreach (config('assistant.pref_keys', []) as $key) {
            $id = $savedIds[$key] ?? null;
            $listName = self::PREF_SOURCE[$key] ?? null;
            $name = null;
            if ($id !== null && $listName) {
                foreach ($options[$listName] as $opt) {
                    if ((string) $opt['id'] === (string) $id) {
                        $name = $opt['name'];
                        break;
                    }
                }
            }
            $defaults[$key] = [
                'id'   => $id !== null ? (int) $id : null,
                'name' => $name,             // null = not set, or the id no longer resolves
                'options_key' => $listName,  // tells the UI which picker to open
            ];
        }

        return response()->json([
            'success'    => true,
            'defaults'   => $defaults,
            'options'    => $options,
            'senders'    => $this->senderList(),
            'forwarders' => $this->forwarderList((int) $user->id),
        ]);
    }

    /**
     * PUT /api/assistant/settings/default — set (or clear) one default.
     * Reuses set_default's server-side resolution: value may be an id (from a
     * picker) or a name; empty value clears the default.
     */
    public function setDefault(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            'key'   => 'required|string',
            'value' => 'nullable',   // string id/name, or empty to clear
        ]);
        $key = (string) $request->input('key');
        if (!in_array($key, config('assistant.pref_keys', []), true)) {
            return response()->json(['success' => false, 'message' => 'Unknown preference.'], 422);
        }

        $value = trim((string) $request->input('value', ''));

        // Empty value = clear the default (set_default rejects empty on purpose).
        if ($value === '') {
            $prefs = $this->prefs((int) $user->id);
            unset($prefs[$key]);
            DB::table('t_ai_user_prefs')->updateOrInsert(
                ['user_id' => $user->id],
                ['prefs_json' => json_encode($prefs), 'updated_at' => now()]
            );
            return response()->json(['success' => true, 'message' => 'Default cleared.', 'resolved_to' => null]);
        }

        $res = $this->tools->call('set_default', ['key' => $key, 'value' => $value], $user);
        if (!empty($res['error'])) {
            return response()->json(['success' => false, 'message' => $res['error']], 422);
        }

        return response()->json([
            'success'     => true,
            'message'     => 'Saved: ' . ($res['resolved_to'] ?? ''),
            'resolved_to' => $res['resolved_to'] ?? null,
        ]);
    }

    /** POST /api/assistant/settings/sender — add or update a sender→bank mapping. */
    public function saveSender(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            'id'                   => 'nullable|integer',
            'sender_id'            => 'required|string|max:64',
            'receiving_account_id' => 'nullable|integer',
            'label'                => 'nullable|string|max:120',
        ]);

        // Normalize the sender the same way the parser will look it up, so the
        // map actually hits: trimmed, upper-cased (SMS sender ids are
        // case-insensitive and often all-caps).
        $senderId = mb_strtoupper(trim((string) $request->input('sender_id')));
        $bankId = $request->input('receiving_account_id') ?: null;

        if ($bankId && !DB::table('t_fin_online_receiving_accounts')->where('id', $bankId)->where('is_active', 1)->exists()) {
            return response()->json(['success' => false, 'message' => 'That bank does not exist.'], 422);
        }

        $data = [
            'sender_id'            => $senderId,
            'receiving_account_id' => $bankId,
            'label'                => $request->input('label') ?: null,
            'is_active'            => 1,
            'updated_at'           => now(),
        ];

        // Upsert on the unique sender_id, so re-teaching a sender re-points it
        // rather than erroring on the unique key.
        $existing = DB::table('t_ai_sms_senders')->where('sender_id', $senderId)->first();
        if ($existing) {
            DB::table('t_ai_sms_senders')->where('id', $existing->id)->update($data);
        } else {
            $data['created_by'] = $user->id;
            $data['created_at'] = now();
            DB::table('t_ai_sms_senders')->insert($data);
        }

        return response()->json(['success' => true, 'message' => 'Sender saved.', 'senders' => $this->senderList()]);
    }

    /** DELETE /api/assistant/settings/sender/{id} */
    public function deleteSender(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        DB::table('t_ai_sms_senders')->where('id', (int) $id)->delete();
        return response()->json(['success' => true, 'message' => 'Sender removed.', 'senders' => $this->senderList()]);
    }

    /** POST /api/assistant/settings/forwarder — add a trusted forwarding number. */
    public function saveForwarder(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            'phone' => 'required|string|max:20',
            'label' => 'nullable|string|max:120',
        ]);

        // Normalize through the SAME DB function the WhatsApp pipeline uses, so
        // a stored forwarder matches an incoming webhook sender later (Phase 5).
        $phone = $this->normalizePhone((string) $request->input('phone'));
        if (!$phone) {
            return response()->json(['success' => false, 'message' => 'That does not look like a valid number.'], 422);
        }

        // Manual upsert (same pattern as saveSender) so a re-add updates the
        // label without clobbering the original created_at.
        $data = [
            'user_id'    => $user->id,
            'label'      => $request->input('label') ?: null,
            'is_active'  => 1,
            'updated_at' => now(),
        ];
        $existing = DB::table('t_ai_trusted_forwarders')->where('phone', $phone)->first();
        if ($existing) {
            DB::table('t_ai_trusted_forwarders')->where('id', $existing->id)->update($data);
        } else {
            $data['phone'] = $phone;
            $data['created_at'] = now();
            DB::table('t_ai_trusted_forwarders')->insert($data);
        }

        return response()->json(['success' => true, 'message' => 'Number added.', 'forwarders' => $this->forwarderList((int) $user->id)]);
    }

    /** DELETE /api/assistant/settings/forwarder/{id} */
    public function deleteForwarder(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        DB::table('t_ai_trusted_forwarders')->where('id', (int) $id)->delete();
        return response()->json(['success' => true, 'message' => 'Number removed.', 'forwarders' => $this->forwarderList((int) $user->id)]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function allowed($user): bool
    {
        return $user && $user->hasMobilePermission('use_ai_assistant');
    }

    private function prefs(int $userId): array
    {
        $row = DB::table('t_ai_user_prefs')->where('user_id', $userId)->value('prefs_json');
        return $row ? (json_decode($row, true) ?: []) : [];
    }

    private function senderList(): array
    {
        return DB::table('t_ai_sms_senders as s')
            ->leftJoin('t_fin_online_receiving_accounts as b', 'b.id', '=', 's.receiving_account_id')
            ->orderBy('s.sender_id')
            ->get(['s.id', 's.sender_id', 's.receiving_account_id', 's.label', 's.is_active', 'b.name as bank_name'])
            ->map(fn($s) => [
                'id'                   => $s->id,
                'sender_id'            => $s->sender_id,
                'receiving_account_id' => $s->receiving_account_id,
                'bank_name'            => $s->bank_name,     // null = seen but unmapped
                'label'                => $s->label,
                'is_active'            => (bool) $s->is_active,
            ])->all();
    }

    private function forwarderList(int $userId): array
    {
        return DB::table('t_ai_trusted_forwarders')
            ->orderBy('created_at')
            ->get(['id', 'phone', 'label', 'is_active'])
            ->map(fn($f) => [
                'id'        => $f->id,
                'phone'     => $f->phone,
                'label'     => $f->label,
                'is_active' => (bool) $f->is_active,
            ])->all();
    }

    /**
     * Normalize a phone to the system's canonical form via fn_normalize_phone
     * (the same function the WhatsApp phone pipeline uses — see
     * WHATSAPP-PHONE-HANDLING). Falls back to a minimal PK rule if the function
     * is missing on a given DB. Returns null if nothing usable.
     */
    private function normalizePhone(string $raw): ?string
    {
        try {
            $n = DB::selectOne('SELECT fn_normalize_phone(?) AS n', [$raw])->n ?? null;
            if ($n) return $n;
        } catch (\Throwable $e) {
            // fall through to the inline rule
        }

        // Fallback MUST emit the same canonical form fn_normalize_phone does
        // (10-digit local, e.g. 3001234567) — a 92-prefixed row here would
        // never match a webhook sender normalized by the DB function later.
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') return null;
        if (str_starts_with($digits, '92')) {
            $digits = substr($digits, 2);                  // 92 3xx… → 3xx…
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);                  // 03xx… → 3xx…
        }
        return strlen($digits) === 10 ? $digits : null;
    }
}
