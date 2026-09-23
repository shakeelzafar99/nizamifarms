<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\LedgerWatchModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cash pill's engine — "has anyone else moved money on MY tills?" (Sep-2026).
 *
 * Origin: a Rs 150,000 vendor payment posted against NF Cash from a phone, correct by
 * every permission rule, spotted days later only because the balance looked wrong. The
 * pill does not block anything; it makes the same event arrive as a notification instead
 * of an archaeology exercise.
 *
 * ⭐ Scope = the accounts the viewer is TAGGED on (t_fin_account_users), so it is
 * self-targeting: Shabib watches NF Cash because he is on NF Cash. Somebody tagged on
 * nothing gets a count of 0 and the pill hides itself — the same "no access → no bulb"
 * contract the day-review pill uses, and the reason this needs no new permission.
 */
class LedgerWatchService
{
    /** How many rows the drawer shows. The owner asked for "last 10 records". */
    public const LIST_LIMIT = 10;

    /**
     * ⭐⭐ Row types the pill IGNORES — the order pipeline.
     *
     * Measured on the replica before this list existed: of 420 rows on Shabib's accounts in
     * 14 days that were "not his hand", **280 were rider deliveries** — invoices, order
     * payments and credit grants posted to the ONLINE account and L1-approved by Taimur on
     * Online Approvals. Fifteen notifications a day about ordinary deliveries is how a manager
     * learns to ignore the bulb, and the one Rs 150,000 vendor payment drowns in them.
     *
     * Those rows already have their own surface (Online Approvals / Daily Closing). What this
     * pill is FOR is a manager's hand on a till — vendor payment, expense, transfer, salary,
     * advance, deposit, purchase, adjustment, reversal — and with the pipeline removed that is
     * exactly what is left (140 rows / 14 days, all Taimur's genuine payments).
     *
     * ⚠ The Hub's "Not me" filter deliberately does NOT apply this list: a ledger page must be
     * complete. Only the notification is curated.
     */
    public const ORDER_FLOW_TYPES = [
        LedgerModel::TYPE_INVOICE,
        LedgerModel::TYPE_ORDER_PAYMENT,
        'customer_credit_grant',
        LedgerModel::TYPE_TIP_COLLECTED,
    ];

    /**
     * ⭐⭐ The tills this person KEEPS — `is_keeper`, not "tagged on".
     *
     * The first cut watched every account the viewer was tagged on, and the owner's screenshot
     * (as Taimur, tagged on 7 accounts) showed why that is wrong: Qasim paying a vendor from the
     * NF Food till he runs, Shabib filing riders' petrol from NF Cash, and rider settlements into
     * Shabib's till that Shabib had already approved — none of it Taimur's concern, all of it
     * clutter, and the one entry that matters buried in it.
     *
     * "Tagged on" means *may spend from*. The pill is not about spending; it is about the drawer
     * you are answerable for. So it watches exactly the tills you are keeper of — today, Shabib
     * and NF Cash — and shows nothing to anybody else. Someone who wants their own till watched
     * ticks "Holds the cash" on it. Same source of truth as the check-out count.
     */
    public function watchedAccountIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // cash AND bank: watching is not counting — see TillCountService::keeperAccounts.
        return app(TillCountService::class)->keeperAccounts($userId, false)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** This person's "seen up to here" marker (0 = never looked). */
    public function watermark(int $userId): int
    {
        return (int) (LedgerWatchModel::where('user_id', $userId)->value('last_seen_ledger_id') ?? 0);
    }

    /**
     * How many rows are waiting for him.
     *
     * ⚠ The "not my hand" test needs `approved_by` as well as the actor, so it cannot be
     * done in SQL without duplicating the rule (see LedgerModel::isSomeoneElsesHand — the
     * one place it lives). Instead SQL narrows to the cheap part — my accounts, newer than
     * my watermark, actually applied — and the rule runs in PHP over what survives. That
     * set is tiny by construction: it is bounded by what has happened since he last looked.
     */
    public function unread(int $userId, ?int $limit = null): Collection
    {
        $accountIds = $this->watchedAccountIds($userId);
        if (empty($accountIds)) {
            return collect();
        }

        $since = $this->watermark($userId);

        // Count path: no relations. The "whose hand" test reads three columns on the row itself,
        // and this is polled once a minute by every tagged manager on every page.
        //
        // ⭐ First day: with no watermark yet, "everything newer than 0" is months of history —
        // the bulb would open on "200" and mean nothing. Until he has looked once, only the
        // last 7 days count as unread. One open sets the real watermark and this never applies again.
        $rows = $this->rowsFor($accountIds, $userId, $limit ?? 200, $since, false)
            ->filter(fn (LedgerModel $r) => $this->isUnread($r, $since));

        return $rows->take($limit ?? PHP_INT_MAX)->values();
    }

    /**
     * The ONE unread test, so the badge and the drawer's dots can never disagree (they did:
     * badge "2", six dots — the first-day floor was applied to one and not the other).
     */
    private function isUnread(LedgerModel $r, int $since): bool
    {
        if ((int) $r->id <= $since) {
            return false;
        }
        if ($since === 0) {
            return $r->created_at && $r->created_at->gte(now()->subDays(7));
        }
        return true;
    }

    public function unreadCount(int $userId): int
    {
        return $this->unread($userId)->count();
    }

    /**
     * The drawer's list: the last N rows on his accounts that were not his own hand,
     * READ OR NOT, each flagged with whether it is new since he last looked.
     *
     * ⭐ Showing read ones too is deliberate. A notification that empties itself the moment
     * it is opened is useless the second time someone asks "what was that payment again?".
     */
    public function recent(int $userId, int $limit = self::LIST_LIMIT): array
    {
        $accountIds = $this->watchedAccountIds($userId);
        if (empty($accountIds)) {
            return ['items' => [], 'unread' => 0, 'latest_id' => 0, 'watching' => 0];
        }

        $since = $this->watermark($userId);
        // Scan deep enough to actually fill the list: on a till the keeper works himself, most
        // recent rows are his own hand and drop out, so 60 candidates gave a drawer of six.
        $rows  = $this->rowsFor($accountIds, $userId, max($limit * 40, 400), null);
        $shown = $rows->take($limit);
        // web / mobile / system for all of them in ONE query — per-row lookups here would
        // be ten round trips every time the drawer opens.
        $sources = $this->sourcesFor($shown->pluck('id')->all());

        $items = $shown->map(function (LedgerModel $r) use ($accountIds, $since, $sources) {
            // Which of HIS accounts this row moved (a transfer can touch two; name the one
            // he watches, preferring the side the money left).
            $acct = in_array((int) $r->from_account_id, $accountIds, true)
                ? $r->fromAccount
                : $r->toAccount;
            $acctId = (int) ($acct->id ?? 0);

            return [
                'id'             => (int) $r->id,
                'account'        => $acct->account_name ?? '—',
                'type'           => LedgerModel::typeLabel($r->transaction_type),
                'description'    => \Illuminate\Support\Str::limit((string) $r->description, 70),
                'amount'         => (float) $r->amount,
                'effect'         => $acctId ? round($r->effectOnAccount($acctId), 2) : 0.0,
                'who'            => $r->actorName(),
                'source'         => $sources[(int) $r->id] ?? null,
                'typed_at'       => $r->created_at?->format('M j, H:i'),
                'shows_as'       => $r->transaction_date
                    ? \Carbon\Carbon::parse($r->transaction_date)->format('M j')
                    : null,
                'days_backdated' => $r->daysBackdated(),
                'unread'         => $this->isUnread($r, $since),
                'url'            => $acctId
                    ? route('fin.hub.account', ['id' => $acctId]) . '#txn-' . $r->id
                    : route('fin.ledger.show', $r->id),
            ];
        })->values()->all();

        return [
            'items'     => $items,
            'unread'    => $rows->filter(fn ($r) => $this->isUnread($r, $since))->count(),
            'latest_id' => (int) (LedgerModel::max('id') ?? 0),
            'watching'  => count($accountIds),
        ];
    }

    /**
     * Mark everything up to $ledgerId as seen.
     *
     * ⭐ Owner's rule: "once Shabib views it, it can be read". Called when the drawer opens,
     * with the newest id the SERVER knows about — not the newest id in the list — so a row
     * that landed between the list being built and the drawer opening is not silently
     * skipped over.
     */
    public function markSeen(int $userId, ?int $ledgerId = null): int
    {
        $id = $ledgerId ?: (int) (LedgerModel::max('id') ?? 0);
        $current = $this->watermark($userId);
        // Never walk the watermark backwards — two tabs open would otherwise resurrect
        // rows the person has already dealt with.
        $id = max($id, $current);

        LedgerWatchModel::updateOrCreate(
            ['user_id' => $userId],
            ['last_seen_ledger_id' => $id, 'last_seen_at' => now()]
        );

        return $id;
    }

    /**
     * Shared query: applied rows on $accountIds, newest first, that are not $userId's hand.
     * `$since` narrows to the unread tail; null means "however far back you need to look".
     */
    private function rowsFor(array $accountIds, int $userId, int $scan, ?int $since, bool $withRelations = true): Collection
    {
        $q = LedgerModel::query()
            ->when($withRelations, fn ($qq) => $qq->with(['fromAccount', 'toAccount', 'enteredBy', 'createdBy']))
            ->where(function ($w) use ($accountIds) {
                $w->whereIn('from_account_id', $accountIds)
                  ->orWhereIn('to_account_id', $accountIds);
            })
            // Only money that actually moved. A pending row has not changed the balance
            // he is being asked to watch, and announcing it would be a second, competing
            // approvals queue.
            ->where('balance_updated', 1)
            // Not the order pipeline — see ORDER_FLOW_TYPES.
            ->whereNotIn('transaction_type', self::ORDER_FLOW_TYPES);

        if ($since !== null) {
            $q->where('id', '>', $since);
        }

        return $q->orderByDesc('id')
            ->limit($scan)
            ->get()
            ->filter(fn (LedgerModel $r) => $r->isSomeoneElsesHand($userId))
            ->values();
    }

    /**
     * web / mobile / system per ledger id, read off the audit trail — the ledger row itself
     * does not record which surface posted it, and "Taimur, on his phone" is most of the
     * value of the notification.
     */
    private function sourcesFor(array $ledgerIds): array
    {
        if (empty($ledgerIds)) {
            return [];
        }

        return DB::table('t_sys_audit_log')
            ->where('entity_type', 'ledger')
            ->where('action', 'created')
            ->whereIn('entity_id', $ledgerIds)
            ->pluck('source', 'entity_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
