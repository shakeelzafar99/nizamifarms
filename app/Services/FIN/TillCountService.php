<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\AccountUserModel;
use App\Models\FIN\CashCountModel;
use App\Models\FIN\LedgerModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The till keeper's daily count — the ONE place a count is taken and the ONE place the
 * drift since a count is worked out (Sep-2026).
 *
 * Both doors (mobile check-out, Hub button) come through `record()`, so the seal can
 * never be captured two different ways. Both readers (the ledger info row, the header
 * chip) come through `withDrift()`, so the page can never disagree with itself.
 *
 * ⚠ Nothing here moves money. See CashCountModel's header for the charter.
 */
class TillCountService
{
    /** Amounts below this are "the same number" — the tolerance the ledger page already uses. */
    private const EPSILON = 0.005;

    /** How many row ids a checkpoint line names before it says "+N more". */
    private const MAX_IDS_SHOWN = 8;

    /**
     * The accounts $userId is KEEPER of — the ones they answer for.
     *
     * Two different questions hang off this tag, and they are deliberately not the same set:
     *
     *  • COUNTING ($cashOnly = true, the default): only a CASH till. You cannot count a bank
     *    by opening a drawer, and asking someone to would train them to type whatever the
     *    screen says. Banks already have their own truth — the Banks tab and the statement.
     *  • WATCHING ($cashOnly = false, the cash pill): cash AND bank. Owner (Sep-22): "isn't
     *    Taimur holding the online account?" — he is, and a transfer out of ONLINE by somebody
     *    else is exactly what he should be told about, even though he will never be asked to
     *    count it.
     */
    public function keeperAccounts(int $userId, bool $cashOnly = true): Collection
    {
        if ($userId <= 0) {
            return collect();
        }

        return AccountModel::query()
            ->join('t_fin_account_users AS au', 'au.account_id', '=', 't_fin_accounts.id')
            ->where('au.user_id', $userId)
            ->where('au.is_keeper', 1)
            ->where('t_fin_accounts.is_active', 1)
            ->whereIn('t_fin_accounts.account_category', $cashOnly
                ? [AccountModel::CATEGORY_CASH]
                : [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK])
            ->orderBy('t_fin_accounts.account_name')
            ->select('t_fin_accounts.*')
            ->get();
    }

    /** Is this person the keeper of this particular till? */
    public function isKeeper(int $userId, int $accountId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return AccountUserModel::where('account_id', $accountId)
            ->where('user_id', $userId)
            ->where('is_keeper', 1)
            ->exists();
    }

    /**
     * What the app/page needs to ASK the question: which till, and what the books say.
     *
     * ⭐ Returns the system figure so the screen can show "System says Rs X" — but the
     * input is never pre-filled with it. An amount typed from memory is the entire point
     * of the exercise; an anchored one proves nothing.
     */
    public function askContext(int $userId): ?array
    {
        $account = $this->keeperAccounts($userId)->first();
        if (!$account) {
            return null;
        }

        $today = $this->latestForDay($account->id, Carbon::today());

        return [
            'account_id'     => (int) $account->id,
            'account_name'   => $account->account_name,
            'account_code'   => $account->account_code,
            'system_balance' => round((float) $account->getEffectiveBalance(), 2),
            'last_ledger_id' => (int) (LedgerModel::max('id') ?? 0),
            'as_of'          => now()->format('Y-m-d H:i'),
            // So the app can say "you already counted at 21:05" instead of asking twice
            // without acknowledging it. A recount is still allowed — see record().
            'counted_today'  => $today ? [
                'amount'     => (float) $today->counted_amount,
                'counted_at' => $today->counted_at?->format('H:i'),
                'matched'    => $today->matches(),
            ] : null,
        ];
    }

    /**
     * Take a count.
     *
     * ⭐⭐ The seal and the system figure are read INSIDE the transaction, with the account
     * row locked, so "what the books said" and "which rows existed" describe the same
     * instant. Read outside the lock they could straddle a concurrent posting and the
     * checkpoint would be built on two different moments.
     *
     * Deliberately APPEND-only, not one-per-day: the owner asked to be able to recount
     * after a big vendor payment, and each count is its own checkpoint with its own seal.
     */
    public function record(
        AccountModel $account,
        int $userId,
        float $countedAmount,
        string $source = CashCountModel::SOURCE_HUB,
        ?int $attendanceId = null,
        ?string $note = null
    ): CashCountModel {
        return DB::transaction(function () use ($account, $userId, $countedAmount, $source, $attendanceId, $note) {
            $locked = AccountModel::lockForUpdate()->findOrFail($account->id);

            $system = round((float) $locked->getEffectiveBalance(), 2);
            $counted = round($countedAmount, 2);
            $lastLedgerId = (int) (LedgerModel::max('id') ?? 0);

            return CashCountModel::create([
                'account_id'     => (int) $locked->id,
                'user_id'        => $userId,
                'counted_amount' => $counted,
                'system_balance' => $system,
                // + he holds MORE than the books say, − he is short.
                'difference'     => round($counted - $system, 2),
                'last_ledger_id' => $lastLedgerId,
                'counted_at'     => now(),
                'source'         => $source,
                'attendance_id'  => $attendanceId,
                'note'           => $note !== null && $note !== '' ? mb_substr($note, 0, 200) : null,
            ]);
        });
    }

    /** The most recent count on this till, for the header chip. Drift included. */
    public function latest(int $accountId): ?array
    {
        $rows = $this->withDrift($accountId, null, 1);
        return $rows->first();
    }

    /** The count taken on a given day, if any (latest wins). */
    public function latestForDay(int $accountId, Carbon $day): ?CashCountModel
    {
        return CashCountModel::where('account_id', $accountId)
            ->whereDate('counted_at', $day->toDateString())
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ⭐⭐ THE CHECKPOINT READER — counts in the window, each carrying what happened to the
     * balance BEHIND IT.
     *
     * Two kinds of drift, both bounded by the NEXT count (after which that count's own line
     * takes over the story — otherwise one late row would be reported on every line above it):
     *
     *  • `late`    — rows that arrived AFTER the seal but are dated ON OR BEFORE the count
     *                day. This is the backdated-entry case the whole feature exists for: the
     *                row hides itself up the page next to older entries, but it moved today's
     *                balance. Rows dated in the FUTURE are excluded — a forward-dated entry
     *                is not rewriting history.
     *  • `touched` — rows that already existed at the seal and were CHANGED since (approved,
     *                reversed, amount edited, re-dated, deleted). A deposit approved after the
     *                count moves the balance just as surely as a new row does.
     *
     * ⚠⚠ `touched` is read from t_sys_audit_log, NOT from `updated_at > counted_at`. The
     * obvious version was tried first and was unusable: on the replica it reported **1,665
     * "changed" entries** against a single count, because `updated_at` moves for reasons that
     * have nothing to do with money — a settlement flag, a bill image, a bulk backfill, and
     * `saveQuietly()` stamping balance_updated. The audit log records what a PERSON actually
     * changed, is indexed on (entity_type, entity_id) and `at`, and — the part `updated_at`
     * can never give — it survives a DELETE, which is the one edit that erases its own row.
     *
     * Bounded work regardless of how many counts are on screen: the candidates are fetched
     * once and bucketed in PHP. An N+1 here would fire on every account page load.
     *
     * ⚠ `at` is a DATETIME, like `counted_at` — the two are directly comparable on any clock.
     * (This is also why `counted_at` is not a TIMESTAMP.)
     */
    public function withDrift(int $accountId, ?Carbon $since = null, ?int $limit = null): Collection
    {
        $q = CashCountModel::with('user')->where('account_id', $accountId);
        if ($since) {
            $q->where('counted_at', '>=', $since);
        }
        $counts = $q->orderByDesc('counted_at')->orderByDesc('id')
            ->when($limit, fn ($qq) => $qq->limit($limit))
            ->get();

        if ($counts->isEmpty()) {
            return collect();
        }

        // Oldest → newest, so each count can see the one that supersedes it.
        $asc = $counts->sortBy([['counted_at', 'asc'], ['id', 'asc']])->values();
        $minSeal = (int) $asc->min('last_ledger_id');
        $minAt   = $asc->first()->counted_at;

        $late = LedgerModel::query()
            ->where(fn ($w) => $w->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId))
            ->where('id', '>', $minSeal)
            ->where('balance_updated', 1)
            ->orderBy('id')
            ->get(['id', 'transaction_date', 'transaction_type', 'amount', 'from_account_id', 'to_account_id', 'created_at', 'entered_by', 'created_by']);

        $touched = $this->editsSince($accountId, $minAt, (int) $asc->max('last_ledger_id'));

        $out = [];
        foreach ($asc as $i => $c) {
            $next     = $asc->get($i + 1);
            $seal     = (int) $c->last_ledger_id;
            $nextSeal = $next ? (int) $next->last_ledger_id : null;
            $countDay = $c->counted_at ? $c->counted_at->copy()->endOfDay() : null;

            $lateRows = $late->filter(function ($r) use ($seal, $nextSeal, $countDay) {
                if ((int) $r->id <= $seal) {
                    return false;
                }
                if ($nextSeal !== null && (int) $r->id > $nextSeal) {
                    return false;
                }
                if (!$countDay) {
                    return false;
                }
                return Carbon::parse($r->transaction_date)->startOfDay()->lte($countDay);
            })->values();

            $touchedRows = $touched->filter(function (array $e) use ($seal, $c, $next) {
                if ($e['ledger_id'] > $seal) {
                    return false;   // it did not exist when he counted — that is `late`, not a change
                }
                if (!$c->counted_at || $e['at']->lte($c->counted_at)) {
                    return false;
                }
                // Once the next count is taken it re-seals everything — stop reporting there.
                if ($next && $next->counted_at && $e['at']->gt($next->counted_at)) {
                    return false;
                }
                return true;
            })->values();

            $lateNet = 0.0;
            foreach ($lateRows as $r) {
                $lateNet += $r->effectOnAccount($accountId);
            }

            $out[] = [
                'id'             => (int) $c->id,
                'account_id'     => (int) $c->account_id,
                'user_id'        => (int) $c->user_id,
                'who'            => $c->user->fullname ?? 'Someone',
                'counted_amount' => (float) $c->counted_amount,
                'system_balance' => (float) $c->system_balance,
                'difference'     => (float) $c->difference,
                'matched'        => $c->matches(),
                'counted_at'     => $c->counted_at,
                'source'         => $c->source,
                'note'           => $c->note,
                'last_ledger_id' => $seal,
                'late_count'     => $lateRows->count(),
                'late_net'       => round($lateNet, 2),
                // ⚠ Capped. A checkpoint line that prints two hundred ids is not a warning,
                // it is a wall — the COUNT is the alarm, the ids are a starting point.
                'late_ids'       => $lateRows->take(self::MAX_IDS_SHOWN)->pluck('id')->all(),
                'late_more'      => max(0, $lateRows->count() - self::MAX_IDS_SHOWN),
                'touched_count'  => $touchedRows->count(),
                'touched_ids'    => $touchedRows->take(self::MAX_IDS_SHOWN)->pluck('ledger_id')->all(),
                'touched_more'   => max(0, $touchedRows->count() - self::MAX_IDS_SHOWN),
                'touched_deleted' => $touchedRows->where('deleted', true)->count(),
            ];
        }

        // Back to newest-first, which is how every reader wants it.
        return collect(array_reverse($out));
    }

    /**
     * Edits a PERSON made to this account's rows after $since, from the audit trail.
     *
     * Returns [['ledger_id' => int, 'at' => Carbon, 'action' => string, 'deleted' => bool], …].
     *
     * ⭐ Deletes are the reason this reads the audit log rather than the ledger: a deleted row
     * is gone from t_fin_ledger entirely, yet removing it moved the balance. The tombstone
     * keeps the old from/to account ids, so a delete can still be attributed to this till.
     */
    private function editsSince(int $accountId, Carbon $since, int $maxSeal): Collection
    {
        $audit = DB::table('t_sys_audit_log')
            ->where('entity_type', 'ledger')
            // Only edits that can move a balance. A plain 'created' is not an edit, and the
            // settlement-flag churn that made `updated_at` useless never reaches this list
            // unless a watched field actually changed (see LedgerAuditObserver::WATCH).
            ->whereIn('action', ['updated', 'status_approved', 'status_rejected', 'status_reversed', 'deleted'])
            ->where('at', '>', $since)
            ->where('entity_id', '<=', $maxSeal)
            ->orderBy('at')
            ->get(['entity_id', 'at', 'action', 'changes']);

        if ($audit->isEmpty()) {
            return collect();
        }

        // Which of these rows belong to THIS account? One lookup for everything still alive.
        $ids = $audit->pluck('entity_id')->unique()->all();
        $mine = LedgerModel::whereIn('id', $ids)
            ->where(fn ($w) => $w->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId))
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->flip();

        return $audit->filter(function ($a) use ($mine, $accountId) {
            $id = (int) $a->entity_id;
            if ($mine->has($id)) {
                return true;
            }
            // Gone from the ledger → read the tombstone's old account ids.
            if ($a->action !== 'deleted' || !$a->changes) {
                return false;
            }
            $c = json_decode($a->changes, true);
            return (int) ($c['from_account_id']['old'] ?? 0) === $accountId
                || (int) ($c['to_account_id']['old'] ?? 0) === $accountId;
        })->map(fn ($a) => [
            'ledger_id' => (int) $a->entity_id,
            'at'        => Carbon::parse($a->at),
            'action'    => (string) $a->action,
            'deleted'   => $a->action === 'deleted',
        ])->values();
    }

    /** Tolerance-aware equality, so callers never re-invent it. */
    public function isSame(float $a, float $b): bool
    {
        return abs($a - $b) < self::EPSILON;
    }
}
