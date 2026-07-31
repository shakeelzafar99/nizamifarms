<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Services\Assistant\SmsCounterpartyMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The REVIEW side of the assistant's account memory (t_ai_counterparty_map).
 *
 * The map was write-only from a human's point of view: the SMS inbox looked an
 * account UP, but nothing let you look DOWN from a vendor or customer to ask
 * "which bank accounts do we believe belong to them?". That made a wrong tag
 * invisible and un-removable except by re-teaching it from another SMS.
 *
 * These two endpoints back the "Known bank accounts" panel on the Ledger Hub
 * vendor page and in the customer detail modal.
 *
 * SAFETY: deactivate() only ever flips is_active to 0. It never deletes the
 * row (its hit_count / last_seen_at stay as an audit trail) and it never
 * touches money — a map rule only decides what the inbox SUGGESTS.
 */
class CounterpartyAccountController extends Controller
{
    /** GET /finance/counterparty-accounts?entity_type=vendor|customer&entity_id=N */
    public function index(Request $request)
    {
        $request->validate([
            'entity_type' => 'required|in:vendor,customer,account',
            'entity_id'   => 'required|integer',
        ]);

        $rows = DB::table('t_ai_counterparty_map')
            ->where('entity_type', $request->input('entity_type'))
            ->where('entity_id', (int) $request->input('entity_id'))
            ->orderByDesc('is_active')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get(['id', 'account_key', 'name_key', 'hit_count', 'last_seen_at', 'is_active', 'created_at', 'created_by']);

        return response()->json([
            'success'  => true,
            'accounts' => $rows->map(fn($r) => [
                'id'          => (int) $r->id,
                // Account-keyed rules are the strong ones; a name-only rule is
                // shown too, flagged, because it is what a reviewer would
                // otherwise wonder about ("why did it suggest this?").
                'account_key' => $r->account_key,
                'name_key'    => $r->name_key,
                'is_name_only' => $r->account_key === null,
                'hit_count'   => (int) $r->hit_count,
                'last_seen'   => $r->last_seen_at ? \Carbon\Carbon::parse($r->last_seen_at)->format('M j, Y') : null,
                'added'       => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->format('M j, Y') : null,
                'added_by'    => $r->created_by
                    ? DB::table('t_sys_user')->where('id', $r->created_by)->value('fullname')
                    : null,
                'is_active'   => (int) $r->is_active === 1,
            ])->all(),
        ]);
    }

    /**
     * POST /finance/counterparty-accounts — manually teach "this account belongs
     * to X" from the vendor/customer panel, no SMS needed.
     *
     * The input is normalized through the SMS parser's own patterns
     * (BankSmsParser::normalizeKey) so "MBL ACxxx4602" and "PK96UNILxx322" land
     * in the exact key space incoming SMS use — a full IBAN typed off a bank
     * statement is REJECTED with an explanation, because SMS only ever carry
     * masked fragments and such a rule would silently never fire.
     *
     * The response reports how many PAST SMS match the key, so a typo is
     * caught the moment it is saved ("matches 0 past SMS — double-check")
     * instead of weeks later when the suggestion never appears.
     */
    public function store(Request $request)
    {
        if (auth()->user()?->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Read-only access.'], 403);
        }

        $request->validate([
            'entity_type' => 'required|in:vendor,customer,account',
            'entity_id'   => 'required|integer',
            'account'     => 'required|string|max:80',
        ]);
        $type = (string) $request->input('entity_type');
        $id   = (int) $request->input('entity_id');

        // The entity must exist — an orphan rule would render as "vendor #57".
        $exists = match ($type) {
            'vendor'   => DB::table('t_fin_vendors')->where('id', $id)->exists(),
            'customer' => DB::table('t_crm_prod_customer')->where('id', $id)->exists(),
            'account'  => DB::table('t_fin_accounts')->where('id', $id)->exists(),
        };
        if (!$exists) {
            return response()->json(['success' => false, 'message' => 'That ' . $type . ' does not exist.'], 422);
        }

        $key = app(\App\Services\Assistant\BankSmsParser::class)
            ->normalizeKey((string) $request->input('account'));
        if (!$key) {
            return response()->json([
                'success' => false,
                'message' => 'Could not read an account from that. Copy the fragment exactly as the bank SMS shows it — e.g. PK96UNILxx322 or MBL ACxxx4602.',
            ], 422);
        }

        // A FULL unmasked IBAN (24 chars in Pakistan, no x/* masking) would save
        // fine and then never fire: SMS only ever carry masked fragments
        // (PK96UNILxx322 is 13 chars WITH its mask). Refuse with the reason
        // rather than let a dead rule sit there looking configured.
        if (preg_match('/^PK\d{2}[A-Z]{4}[0-9]{14,}$/', $key)) {
            return response()->json([
                'success' => false,
                'message' => 'That looks like a full account number. Bank SMS only show a masked fragment (e.g. PK96UNILxx322), so a full number would never be recognised — copy the account as it appears in an actual SMS.',
            ], 422);
        }

        // An ACTIVE rule pointing somewhere ELSE is never silently overwritten —
        // a mis-typed key stealing another entity's account would misroute every
        // future suggestion. Remove it on its own panel first, deliberately.
        $map = app(SmsCounterpartyMap::class);
        $active = $map->byAccount($key);
        if ($active) {
            if ($active->entity_type === $type && (int) $active->entity_id === $id) {
                return response()->json(['success' => true, 'already' => true,
                    'message' => $key . ' is already saved for this ' . $type . '.']);
            }
            return response()->json(['success' => false,
                'message' => $key . ' is already recognised as ' . $map->entityName($active)
                    . ' (' . $active->entity_type . '). Remove it there first if that is wrong.'], 422);
        }

        $map->save($key, null, $type, $id, null, auth()->id());

        // Instant typo feedback: how many SMS ever carried this exact key?
        $seen = DB::table('t_ai_bank_sms')->where('counterparty_account', $key)->count();

        return response()->json([
            'success' => true,
            'key'     => $key,
            'seen'    => $seen,
            'message' => 'Saved ' . $key . ' — '
                . ($seen > 0
                    ? 'it matches ' . $seen . ' past SMS, so future ones will be recognised.'
                    : 'no bank SMS has shown this account yet. If one should have, double-check the fragment against the SMS text.'),
        ]);
    }

    /** POST /finance/counterparty-accounts/{id}/deactivate — stop trusting a tag. */
    public function deactivate(Request $request, $id)
    {
        if (auth()->user()?->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Read-only access.'], 403);
        }

        $rule = DB::table('t_ai_counterparty_map')->where('id', $id)->first();
        if (!$rule) {
            return response()->json(['success' => false, 'message' => 'That mapping no longer exists.'], 404);
        }

        app(SmsCounterpartyMap::class)->deactivate((int) $id);

        return response()->json([
            'success' => true,
            'message' => 'Removed — ' . ($rule->account_key ?: $rule->name_key)
                . ' will no longer be recognised automatically.',
        ]);
    }
}
