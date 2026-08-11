<?php

namespace App\Services\Payments\Signals;

use App\Models\FIN\PaymentSignal;
use App\Services\Assistant\BankSmsAutoActionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ⭐⭐ Gives a held bank credit a SECOND look. This is the single highest-value
 * piece of the payment-matching work, and it exists because of one fact about
 * how this business runs:
 *
 *   THE INVOICE IS CREATED AT DELIVERY, NOT WHEN THE ORDER IS PLACED.
 *
 * Measured over 1,301 invoices: only 41 (3%) appeared within an hour of the
 * order; the mean gap was 21 hours. Customers, meanwhile, routinely pay AT
 * delivery — or before it. So the bank's credit alert very often arrives while
 * the invoice it belongs to does not yet exist anywhere in the system.
 *
 * The matcher ran exactly once, the moment the SMS landed, against invoices
 * already awaiting approval. Finding nothing, it "held" the credit — and
 * nothing ever looked again. Two real examples from one afternoon:
 *   • Kashif Ahmad, Rs 12,603 — SMS at 13:35, his invoice entered the queue at
 *     13:46. Eleven minutes late, and the money sat unexplained for days while
 *     the invoice was approved with no proof attached.
 *   • Nouman Siddique, Rs 7,600 — paid at 13:25, his invoice appeared at 13:30.
 *     In those five minutes the only invoice near that figure belonged to
 *     someone else, so the credit was tagged to a stranger.
 *
 * This class simply asks again, a little later, when the missing invoice has
 * had time to appear.
 *
 * ── Why it runs on page loads and not on a schedule ────────────────────────
 * There is NO Laravel scheduler on production — `schedule:run` has never
 * executed there, so a cron-driven worker would silently do nothing forever
 * (and its log would look perfectly clean). Every job that actually works on
 * this system piggybacks real web traffic. So this hooks the two screens whose
 * users care about exactly this money, runs AFTER the response is sent
 * (`terminating()`), and throttles itself so the cost is near zero.
 */
class HeldCreditResweeper
{
    private const THROTTLE_KEY = 'payment_signals:resweep:last_run';

    public function __construct(
        private BankSmsAutoActionService $auto,
    ) {
    }

    /**
     * Queue a sweep to run after the current response is delivered. Safe to
     * call from anywhere and as often as you like — the throttle and the
     * feature flag are both checked inside.
     */
    public static function scheduleAfterResponse(): void
    {
        try {
            if (!config('payment_signals.enabled')) {
                return;
            }
            app()->terminating(function () {
                try {
                    app(self::class)->run();
                } catch (\Throwable $e) {
                    Log::warning('[HeldCreditResweep] ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            // Never let bookkeeping break a page render.
        }
    }

    /**
     * Re-run the ingest ladder over recent held credits.
     *
     * @return int how many credits found a home this time
     */
    public function run(bool $force = false): int
    {
        if (!config('payment_signals.enabled')) {
            return 0;
        }
        if (!$force && !$this->takeThrottleSlot()) {
            return 0;
        }

        $days  = (int) config('payment_signals.resweep_days', 7);
        $limit = (int) config('payment_signals.resweep_max_per_run', 30);

        // Candidates: credits still sitting in the inbox unanswered. A credit
        // whose signal already points at an order is NOT re-run — re-guessing a
        // settled question is how a system starts arguing with itself.
        $rows = DB::table('t_ai_bank_sms as s')
            ->leftJoin('t_fin_payment_signal as p', 'p.id', '=', 's.linked_signal_id')
            ->where('s.direction', 'credit')
            // Only genuinely open rows. 'needs_sender' credits are missing the
            // bank identity the ladder needs, and anything else has been dealt
            // with by a human or an earlier sweep.
            ->where('s.status', 'new')
            ->where('s.amount', '>', 0)
            ->whereNotNull('s.receiving_account_id')
            ->where('s.sms_at', '>=', now()->subDays($days))
            ->where(function ($w) {
                $w->whereNull('s.linked_signal_id')
                  ->orWhereNull('p.matched_order_id');
            })
            // ⭐ A HUMAN has already ruled on these — never re-guess them, or
            // the next page load would helpfully undo the correction and the
            // approver would find themselves arguing with the software.
            //
            // Note what is NOT excluded: a credit displaced by real proof
            // (`guess_displaced`) is money that still needs a home — no human
            // rejected it, its old order simply turned out to belong to someone
            // else. Those are exactly what this sweep should re-place, and the
            // no-stacking guard stops it returning to the order it just left.
            ->where(function ($w) {
                $w->whereNull('p.match_reason')
                  ->orWhereNotIn('p.match_reason', PaymentSignal::TERMINAL_REASONS);
            })
            ->orderByDesc('s.sms_at')
            ->limit($limit)
            ->get([
                's.id', 's.user_id', 's.amount', 's.direction', 's.status', 's.reference',
                's.counterparty', 's.counterparty_account', 's.receiving_account_id',
                's.sms_at', 's.raw_body', 's.linked_signal_id',
            ]);

        $placed = 0;
        foreach ($rows as $sms) {
            try {
                if ($this->resweepOne($sms)) {
                    $placed++;
                }
            } catch (\Throwable $e) {
                Log::warning('[HeldCreditResweep] row failed', [
                    'sms_id' => $sms->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        if ($placed > 0) {
            Log::info('[HeldCreditResweep] placed ' . $placed . ' of ' . $rows->count() . ' held credit(s)');
        }
        return $placed;
    }

    /**
     * One credit, one retry.
     *
     * ⚠⚠ The existing held signal is REUSED, never replaced. `linked_signal_id`
     * is the identity of this money in the proof system: creating a second
     * signal would double-count the credit, break the duplicate-reference
     * guard, and could show one payment as two proofs on the same invoice.
     * BankSmsAutoActionService::handleCredit() is idempotent on that basis — it
     * returns immediately if an SMS already has a signal — so the held signal
     * is detached first and handed back for a clean run.
     */
    private function resweepOne(object $sms): bool
    {
        // Re-check the ladder's own entry conditions BEFORE retiring anything.
        // handle() bails silently on these, and a signal deleted for a run that
        // never happens is a credit stripped of its record.
        if (($sms->status ?? '') !== 'new' || !($sms->amount > 0) || !$sms->receiving_account_id) {
            return false;
        }

        $held = $sms->linked_signal_id ? PaymentSignal::find($sms->linked_signal_id) : null;

        // A signal that has since paired (its screenshot arrived) is settled —
        // leave it alone entirely.
        if ($held && ($held->paired_signal_id || $held->matched_order_id)) {
            return false;
        }

        if ($held) {
            // Retire the stale probe so the ladder can run from a clean slate;
            // its facts (amount, reference, time) are re-derived from the SMS.
            DB::table('t_fin_payment_signal_order')->where('signal_id', $held->id)->delete();
            $held->delete();
            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update(['linked_signal_id' => null]);
            $sms->linked_signal_id = null;
        }

        $result = $this->auto->handle($sms);

        // Every deterministic rule has now had its turn. If the credit is still
        // homeless and the bank gave us a name, this is the one moment the AI
        // arbiter is allowed to speak.
        if (!$result) {
            $result = $this->tryAiArbiter($sms);
        }

        if (!$result) {
            return false;
        }

        Log::info('[HeldCreditResweep] ' . ($result['action'] ?? 'placed'), [
            'sms_id'   => $sms->id,
            'customer' => $result['customer'] ?? null,
            'order'    => $result['order_number'] ?? null,
        ]);
        return true;
    }

    /**
     * The last-resort payer-name question — asked at most once per credit, and
     * only when a genuine choice exists.
     */
    private function tryAiArbiter(object $sms): ?array
    {
        $fresh = DB::table('t_ai_bank_sms')->where('id', $sms->id)
            ->first(['ai_name_checked_at', 'linked_signal_id', 'counterparty']);
        if (!$fresh || $fresh->ai_name_checked_at || empty($fresh->counterparty)) {
            return null;
        }

        $candidates = $this->arbiterCandidates((float) $sms->amount, $sms->sms_at ?? null);

        // Stamp BEFORE calling out: whether the model answers, errors, or says
        // "cannot tell", this credit has now had its one question. A crash
        // mid-call must not buy it a second.
        DB::table('t_ai_bank_sms')->where('id', $sms->id)
            ->update(['ai_name_checked_at' => now(), 'updated_at' => now()]);

        if (count($candidates) < 2) {
            return null;
        }

        $picked = app(PayerNameArbiter::class)->choose($fresh->counterparty, $candidates);
        if (!$picked) {
            return null;
        }

        $sms->linked_signal_id = $fresh->linked_signal_id;
        $result = $this->auto->attachResolvedCustomer($sms, (int) $picked['customer_id'], PaymentSignal::REASON_NAME_AI);
        if ($result) {
            $result['customer'] = $picked['customer_name'];
        }
        return $result;
    }

    /**
     * Customers who could plausibly have sent this money: they have an order,
     * open or awaiting approval, whose outstanding balance is near the amount
     * and which existed when the payment was made.
     *
     * A wider slack than the matching tolerance is used ON PURPOSE — this is
     * the set the AI chooses a NAME from, and the cases that reach here are
     * exactly the ones where the figure is a little off (Nouman's credit was
     * Rs 200 over his invoice, far outside the Rs 100 match tolerance). Orders
     * already carrying a payment signal are excluded: they have their answer.
     */
    private function arbiterCandidates(float $amount, $paidAt): array
    {
        if ($amount <= 0) {
            return [];
        }
        $slack = (float) config('payment_signals.ai_arbiter_amount_slack', 500);
        [$from, $to] = PaymentSignalMatcher::guessOrderDateBounds($paidAt);

        $rows = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.payment_status', ['unpaid', 'partial'])
            ->where('o.order_status', '!=', 'cancelled')
            ->where(function ($w) {
                $w->whereNull('c.customer_type')->orWhere('c.customer_type', '!=', 'shop');
            })
            ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
            ->whereRaw('ABS((o.total_price - COALESCE(o.total_paid,0)) - ?) <= ?', [$amount, $slack])
            ->whereBetween('o.order_date', [$from, $to])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('t_fin_payment_signal as ps')
                  ->whereColumn('ps.matched_order_id', 'o.id')
                  ->whereIn('ps.status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH]);
            })
            ->orderByRaw('ABS((o.total_price - COALESCE(o.total_paid,0)) - ?)', [$amount])
            ->limit(12)
            ->get(['o.customer_id', 'c.first_name', 'c.last_name']);

        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            $cid = (int) $r->customer_id;
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $out[] = [
                'customer_id'   => $cid,
                'customer_name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
            ];
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    /**
     * At most one sweep per throttle window across the whole site, so a burst
     * of page loads costs the same as one. Uses an atomic add() rather than
     * get-then-put: two simultaneous requests must not both win the slot.
     */
    private function takeThrottleSlot(): bool
    {
        $mins = (int) config('payment_signals.resweep_throttle_mins', 5);
        if ($mins <= 0) {
            return true;
        }
        try {
            return Cache::add(self::THROTTLE_KEY, now()->toDateTimeString(), now()->addMinutes($mins));
        } catch (\Throwable $e) {
            return false; // no cache = don't risk sweeping on every request
        }
    }
}
