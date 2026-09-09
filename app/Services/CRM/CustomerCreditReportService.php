<?php

namespace App\Services\CRM;

use App\Models\CRM\CustomerCreditModel;
use App\Models\FIN\AccountModel;
use App\Services\CustomerCreditService;
use Illuminate\Support\Facades\DB;

/**
 * Customer balances — the READ side (Sep-2026).
 *
 * Answers the questions a manager asks about the account-balance bucket:
 * who holds money, how much, when was it last added and by whom, what went in
 * and out day by day, and — the reason this exists — does anything look wrong.
 *
 * ⚠⚠ This class NEVER writes. Every correction (approve / reject / remove one
 * entry / clear to zero) goes through CustomerCreditService's existing methods
 * via CustomerCreditController, so the Balances page and the customer panel
 * cannot drift apart about what a correction does.
 *
 * ⭐ It also never re-implements the balance formula. CustomerCreditService owns
 * it (customerBalances / balancesForMany / pendingForMany); this class only
 * decorates those numbers with names, dates, actors and review flags. The one
 * time a screen hand-rolled the sum it silently disagreed with every other
 * screen for merged customers.
 */
class CustomerCreditReportService
{
    /**
     * A grant this close to (or above) its order's total is almost certainly the
     * WHOLE invoice banked by mistake rather than a real overpayment. Rs 5
     * absorbs the rounding you see on real orders (a customer transfers a round
     * 13,445 against a 13,444.70 invoice).
     */
    private const WHOLE_INVOICE_TOLERANCE = 5.00;

    /** Above this multiple of the order total the "extra" is bigger than the sale itself. */
    private const OVERSIZED_MULTIPLE = 1.5;

    /** Flags that mean "a human should look at this". Anything else is informational. */
    public const REVIEW_FLAGS = ['whole_invoice', 'bigger_than_order', 'duplicate_proofs'];

    public function __construct(private CustomerCreditService $credit)
    {
    }

    // =====================================================================
    // OVERVIEW — the totals strip
    // =====================================================================

    /**
     * @param string|null $from  Y-m-d, defaults to the start of this month
     * @param string|null $to    Y-m-d, defaults to today
     */
    public function overview(?string $from = null, ?string $to = null): array
    {
        if (!$this->credit->tableReady()) {
            return $this->emptyOverview();
        }

        $from = $from ?: now()->startOfMonth()->toDateString();
        $to   = $to   ?: now()->toDateString();

        $balances = $this->credit->customerBalances();
        $pending  = $this->credit->pendingForMany();

        // Movement in the window, split the way a manager reads it.
        $moves = DB::table('t_crm_customer_credit')
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES)
            ->get(['entry_type', 'amount', 'source']);

        $added = $used = $cleared = 0.0;
        foreach ($moves as $m) {
            $amt = (float) $m->amount;
            if ($m->entry_type === CustomerCreditModel::TYPE_GRANT) {
                $added += $amt;
            } elseif ($m->entry_type === CustomerCreditModel::TYPE_CONSUME) {
                $used += abs($amt);
            } else {
                $cleared += abs($amt);
            }
        }

        return [
            'held'             => round(array_sum($balances), 2),
            'held_customers'   => count($balances),
            'pending_total'    => round(array_sum(array_column($pending, 'total')), 2),
            'pending_count'    => (int) array_sum(array_column($pending, 'count')),
            'from'             => $from,
            'to'               => $to,
            'added'            => round($added, 2),
            'used'             => round($used, 2),
            'cleared'          => round($cleared, 2),
            'flagged_count'    => $this->flaggedCount(),
            'reconciliation'   => $this->reconciliation(),
        ];
    }

    private function emptyOverview(): array
    {
        return [
            'held' => 0.0, 'held_customers' => 0, 'pending_total' => 0.0, 'pending_count' => 0,
            'from' => null, 'to' => null, 'added' => 0.0, 'used' => 0.0, 'cleared' => 0.0,
            'flagged_count' => 0,
            'reconciliation' => ['ok' => true, 'dormant' => true, 'bucket' => 0.0, 'ledger' => 0.0, 'difference' => 0.0, 'reserved' => 0.0],
        ];
    }

    /**
     * ⭐ The one check that proves the bucket and the books still agree.
     *
     * Compares the credit rows that have actually POSTED (status active) against
     * the CUSTOMER_CREDIT liability account. Reserved consumes are deliberately
     * excluded on both sides: reserving credit against an undelivered order
     * writes no ledger row by design, so counting it here would report a false
     * break every time someone applies a balance to an order.
     */
    public function reconciliation(): array
    {
        $posted = (float) DB::table('t_crm_customer_credit')
            ->where('status', CustomerCreditModel::STATUS_ACTIVE)
            ->sum('amount');

        $reserved = (float) DB::table('t_crm_customer_credit')
            ->where('status', CustomerCreditModel::STATUS_RESERVED)
            ->sum('amount');

        $account = AccountModel::getByCode(CustomerCreditService::ACCOUNT_CODE);
        $ledger  = $account ? (float) $account->current_balance : 0.0;

        $difference = round($posted - $ledger, 2);

        return [
            'ok'         => $account !== null && abs($difference) < 0.01,
            'dormant'    => $account === null,
            'bucket'     => round($posted, 2),
            'ledger'     => round($ledger, 2),
            'difference' => $difference,
            'reserved'   => round($reserved, 2),
        ];
    }

    // =====================================================================
    // TAB A — who holds money
    // =====================================================================

    /**
     * @param array{min?:float|null,max?:float|null,added_by?:int|null,days?:int|null,
     *              flagged?:bool,search?:string|null,sort?:string,include_zero?:bool} $filters
     */
    public function balances(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        if (!$this->credit->tableReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1];
        }

        $includeZero = !empty($filters['include_zero']);
        $min = array_key_exists('min', $filters) && $filters['min'] !== null
            ? (float) $filters['min']
            : ($includeZero ? null : 0.01);
        $max = isset($filters['max']) && $filters['max'] !== null ? (float) $filters['max'] : null;

        $balances = $this->credit->customerBalances($min, $max);

        // "Show zero balances" must still list customers whose money has all been
        // spent or cleared — they have a history worth auditing even at zero.
        if ($includeZero) {
            foreach ($this->customerIdsWithAnyEntry() as $id) {
                if (!array_key_exists($id, $balances)) {
                    $balances[$id] = 0.0;
                }
            }
        }

        if (empty($balances)) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1];
        }

        $ids = array_keys($balances);

        // Per-customer facts, all batched — never one query per row.
        $lastAdded = $this->lastEntryPerCustomer($ids, CustomerCreditModel::TYPE_GRANT);
        $lastUsed  = $this->lastEntryPerCustomer($ids, CustomerCreditModel::TYPE_CONSUME);
        $counts    = $this->entryCountPerCustomer($ids);
        $pending   = $this->credit->pendingForMany($ids);
        $flagged   = $this->flaggedCustomerIds();

        // --- filters that need those facts -------------------------------
        if (!empty($filters['added_by'])) {
            $by  = (int) $filters['added_by'];
            $has = $this->customerIdsWithGrantBy($by);
            $ids = array_values(array_intersect($ids, $has));
        }

        if (!empty($filters['days'])) {
            $cutoff = now()->subDays((int) $filters['days'])->toDateString();
            $recent = $this->customerIdsActiveSince($cutoff);
            $ids    = array_values(array_intersect($ids, $recent));
        }

        if (!empty($filters['flagged'])) {
            $ids = array_values(array_intersect($ids, $flagged));
        }

        $customers = $this->customerRows($ids);

        if (!empty($filters['search'])) {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $ids = array_values(array_filter($ids, function ($id) use ($customers, $needle) {
                $c = $customers[$id] ?? null;
                if (!$c) {
                    return false;
                }

                return str_contains(mb_strtolower($c->name . ' ' . $c->phone . ' #' . $c->id), $needle);
            }));
        }

        // A customer record that no longer exists cannot be shown, but its money
        // must not silently vanish from the totals either — it is still counted
        // in overview() and will show as a flagged orphan in the activity log.
        $ids = array_values(array_filter($ids, fn ($id) => isset($customers[$id])));

        // --- sort ---------------------------------------------------------
        $sort = $filters['sort'] ?? 'balance_desc';
        usort($ids, function ($a, $b) use ($sort, $balances, $lastAdded, $lastUsed, $customers) {
            switch ($sort) {
                case 'balance_asc':
                    return $balances[$a] <=> $balances[$b];
                case 'last_added':
                    return ($lastAdded[$b]->id ?? 0) <=> ($lastAdded[$a]->id ?? 0);
                case 'last_used':
                    return ($lastUsed[$b]->id ?? 0) <=> ($lastUsed[$a]->id ?? 0);
                case 'name':
                    return strcasecmp($customers[$a]->name ?? '', $customers[$b]->name ?? '');
                default:
                    return $balances[$b] <=> $balances[$a];
            }
        });

        $total    = count($ids);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, $page), $lastPage);
        $slice    = array_slice($ids, ($page - 1) * $perPage, $perPage);

        $userNames = $this->userNames(array_merge(
            array_map(fn ($r) => $r->created_by ?? null, $lastAdded),
            array_map(fn ($r) => $r->created_by ?? null, $lastUsed)
        ));

        $orderInfo = $this->orderInfo(array_merge(
            array_map(fn ($r) => $r->order_id ?? null, $lastAdded),
            array_map(fn ($r) => $r->order_id ?? null, $lastUsed)
        ));

        $rows = [];
        foreach ($slice as $id) {
            $c  = $customers[$id];
            $la = $lastAdded[$id] ?? null;
            $lu = $lastUsed[$id] ?? null;

            $rows[] = [
                'customer_id'    => $id,
                'name'           => $c->name,
                'phone'          => $c->phone,
                'balance'        => $balances[$id],
                'pending_total'  => $pending[$id]['total'] ?? 0.0,
                'pending_count'  => $pending[$id]['count'] ?? 0,
                'entries'        => $counts[$id] ?? 0,
                'flagged'        => in_array($id, $flagged, true),
                'last_added'     => $la ? [
                    'date'   => $this->displayDate($la->created_at),
                    'amount' => round((float) $la->amount, 2),
                    'by'     => $userNames[$la->created_by] ?? null,
                    'order'  => $la->order_id ? ($orderInfo[$la->order_id]['number'] ?? null) : null,
                    'order_total' => $la->order_id ? ($orderInfo[$la->order_id]['total'] ?? null) : null,
                ] : null,
                'last_used'      => $lu ? [
                    'date'   => $this->displayDate($lu->created_at),
                    'amount' => round(abs((float) $lu->amount), 2),
                    'order'  => $lu->order_id ? ($orderInfo[$lu->order_id]['number'] ?? null) : null,
                    'order_total' => $lu->order_id ? ($orderInfo[$lu->order_id]['total'] ?? null) : null,
                ] : null,
            ];
        }

        return [
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
        ];
    }

    // =====================================================================
    // TAB B — the activity log
    // =====================================================================

    /**
     * @param array{from?:string,to?:string,type?:string,source?:string,status?:string,
     *              actor?:int,customer_id?:int,flagged?:bool} $filters
     */
    public function activity(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        if (!$this->credit->tableReady()) {
            return ['rows' => [], 'days' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1];
        }

        $q = DB::table('t_crm_customer_credit');
        $this->applyActivityFilters($q, $filters);

        $total    = (clone $q)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, $page), $lastPage);

        $raw = $q->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        // Flags are computed for the WHOLE filtered set when the caller wants
        // only flagged rows, otherwise just for this page.
        $flagMap = $this->flagsForCreditIds($raw->pluck('id')->all());

        if (!empty($filters['flagged'])) {
            $raw = $raw->filter(fn ($r) => !empty(array_intersect(
                array_column($flagMap[$r->id] ?? [], 'key'),
                self::REVIEW_FLAGS
            )))->values();
        }

        $customers   = $this->customerRows($raw->pluck('customer_id')->unique()->all());
        $orderInfo = $this->orderInfo($raw->pluck('order_id')->all());
        $userNames   = $this->userNames(array_merge(
            $raw->pluck('created_by')->all(),
            $raw->pluck('approved_by')->all(),
            $raw->pluck('voided_by')->all()
        ));
        $banks       = $this->bankNamesForLedger($raw->pluck('ledger_transaction_id')->filter()->all());
        $balancesNow = $this->credit->balancesForMany($raw->pluck('customer_id')->unique()->all());

        // A running balance only means something along ONE customer's own
        // timeline, so it is offered only when the log is filtered to one.
        $running = !empty($filters['customer_id'])
            ? $this->runningBalances((int) $filters['customer_id'])
            : [];

        $rows = [];
        foreach ($raw as $r) {
            $amount  = (float) $r->amount;
            $counts  = in_array($r->status, CustomerCreditModel::SPENDABLE_STATUSES, true);
            $flags   = $flagMap[$r->id] ?? [];

            $rows[] = [
                'id'              => (int) $r->id,
                'customer_id'     => (int) $r->customer_id,
                'customer_name'   => $customers[$r->customer_id]->name ?? ('Customer #' . $r->customer_id),
                'entry_type'      => $r->entry_type,
                'type_label'      => $this->typeLabel($r),
                'status'          => $r->status,
                'status_label'    => $this->statusLabel($r),
                'amount'          => round($amount, 2),
                'amount_abs'      => round(abs($amount), 2),
                'is_credit'       => $amount > 0,
                'counts'          => $counts,
                'source'          => $r->source,
                'source_label'    => $this->sourceLabel($r->source),
                'order_id'        => $r->order_id ? (int) $r->order_id : null,
                'order_number'    => $r->order_id ? ($orderInfo[$r->order_id]['number'] ?? null) : null,
                'order_total'     => $r->order_id ? ($orderInfo[$r->order_id]['total'] ?? null) : null,
                // ⭐ What this entry CLAIMS the customer handed over: the invoice
                // plus the extra we kept. That is the number that exposes a
                // phantom — a Rs 6,000 order with a Rs 6,000 "extra" is claiming
                // they paid Rs 12,000 for it, which nobody did.
                //
                // ⚠ Do NOT show "grant minus invoice" instead. On a genuine
                // partial overpayment (Rs 25.80 extra on a Rs 9,674.20 invoice)
                // that reads as "Rs 9,648.40 under", which is meaningless — the
                // grant is the extra, never the payment.
                'implied_paid' => ($r->entry_type === CustomerCreditModel::TYPE_GRANT
                                   && $r->order_id
                                   && ($orderInfo[$r->order_id]['total'] ?? null) !== null)
                                  ? round((float) $orderInfo[$r->order_id]['total'] + abs((float) $r->amount), 2)
                                  : null,
                'signal_id'       => $r->signal_id ? (int) $r->signal_id : null,
                'bank'            => $r->ledger_transaction_id ? ($banks[$r->ledger_transaction_id] ?? null) : null,
                'reason'          => $r->reason,
                'entered_by'      => $userNames[$r->created_by] ?? null,
                'approved_by'     => $userNames[$r->approved_by] ?? null,
                'voided_by'       => $userNames[$r->voided_by] ?? null,
                'voided_reason'   => $r->voided_reason,
                'date'            => $this->displayDate($r->created_at),
                'date_full'       => $this->displayDateTime($r->created_at),
                'day'             => substr((string) $r->created_at, 0, 10),
                'balance_now'     => $balancesNow[$r->customer_id] ?? 0.0,
                'running_balance' => $running[$r->id] ?? null,
                'flags'           => $flags,
                'needs_review'    => !empty(array_intersect(array_column($flags, 'key'), self::REVIEW_FLAGS)) && $counts,
            ];
        }

        return [
            'rows'      => $rows,
            'days'      => $this->daySubtotals($rows),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /** Added / Used / Cleared / Net for each day present on this page of rows. */
    private function daySubtotals(array $rows): array
    {
        $days = [];
        foreach ($rows as $r) {
            $d = $r['day'];
            if (!isset($days[$d])) {
                $days[$d] = ['day' => $d, 'added' => 0.0, 'used' => 0.0, 'cleared' => 0.0, 'net' => 0.0, 'count' => 0];
            }
            $days[$d]['count']++;

            // Only rows that COUNT move a balance; a pending or voided row is
            // history, not money, and must not swell a day's totals.
            if (!$r['counts']) {
                continue;
            }
            if ($r['entry_type'] === CustomerCreditModel::TYPE_GRANT) {
                $days[$d]['added'] += $r['amount_abs'];
            } elseif ($r['entry_type'] === CustomerCreditModel::TYPE_CONSUME) {
                $days[$d]['used'] += $r['amount_abs'];
            } else {
                $days[$d]['cleared'] += $r['amount_abs'];
            }
            $days[$d]['net'] += $r['amount'];
        }

        foreach ($days as $d => $v) {
            $days[$d]['added']   = round($v['added'], 2);
            $days[$d]['used']    = round($v['used'], 2);
            $days[$d]['cleared'] = round($v['cleared'], 2);
            $days[$d]['net']     = round($v['net'], 2);
        }

        return $days;
    }

    // =====================================================================
    // TAB C — day by day
    // =====================================================================

    public function daily(?string $from = null, ?string $to = null): array
    {
        if (!$this->credit->tableReady()) {
            return ['rows' => [], 'opening' => 0.0];
        }

        $from = $from ?: now()->startOfMonth()->toDateString();
        $to   = $to   ?: now()->endOfMonth()->toDateString();

        // What was already held the moment this window opened, so the running
        // total on screen is the real held total and not just the window's net.
        $opening = (float) DB::table('t_crm_customer_credit')
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES)
            ->whereRaw('DATE(created_at) < ?', [$from])
            ->sum('amount');

        $raw = DB::table('t_crm_customer_credit')
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->get(['entry_type', 'amount', DB::raw('DATE(created_at) AS day')]);

        $days = [];
        foreach ($raw as $r) {
            $d = $r->day;
            if (!isset($days[$d])) {
                $days[$d] = ['day' => $d, 'added' => 0.0, 'used' => 0.0, 'cleared' => 0.0, 'net' => 0.0];
            }
            $amt = (float) $r->amount;
            if ($r->entry_type === CustomerCreditModel::TYPE_GRANT) {
                $days[$d]['added'] += $amt;
            } elseif ($r->entry_type === CustomerCreditModel::TYPE_CONSUME) {
                $days[$d]['used'] += abs($amt);
            } else {
                $days[$d]['cleared'] += abs($amt);
            }
            $days[$d]['net'] += $amt;
        }

        ksort($days);

        $running = round($opening, 2);
        $rows    = [];
        foreach ($days as $d => $v) {
            $running += $v['net'];
            $rows[] = [
                'day'     => $d,
                'added'   => round($v['added'], 2),
                'used'    => round($v['used'], 2),
                'cleared' => round($v['cleared'], 2),
                'net'     => round($v['net'], 2),
                'held'    => round($running, 2),
            ];
        }

        return ['rows' => array_reverse($rows), 'opening' => round($opening, 2), 'from' => $from, 'to' => $to];
    }

    // =====================================================================
    // REVIEW FLAGS — how a wrong entry announces itself
    // =====================================================================

    /**
     * Flags for a set of credit ids, batched.
     *
     * ⚠ Advisory only. Nothing here blocks or changes money; the fix path is
     * the same "Remove this entry" a manager already has. The rules are written
     * to catch the failure that actually happened on production (one transfer
     * reported by three channels, the whole invoice banked as "extra") without
     * firing on genuine small overpayments.
     *
     * @return array<int, array<int, array{key:string,label:string,level:string}>>
     */
    public function flagsForCreditIds(array $creditIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $creditIds))));
        if (empty($ids)) {
            return [];
        }

        $rows = DB::table('t_crm_customer_credit')->whereIn('id', $ids)->get();

        $orderIds = $rows->pluck('order_id')->filter()->unique()->values()->all();
        $orders   = empty($orderIds) ? [] : DB::table('t_crm_prod_order')
            ->whereIn('id', $orderIds)
            ->get(['id', 'total_price'])
            ->keyBy('id')
            ->all();

        $suspectOrders = $this->ordersWithDuplicateProofs($orderIds);

        $out = [];
        foreach ($rows as $r) {
            $flags  = [];
            $amount = round(abs((float) $r->amount), 2);
            $isGrant = $r->entry_type === CustomerCreditModel::TYPE_GRANT;
            $order  = $r->order_id ? ($orders[$r->order_id] ?? null) : null;
            $total  = $order ? round((float) $order->total_price, 2) : null;

            if ($isGrant && $total !== null && $total > 0) {
                if ($amount > $total * self::OVERSIZED_MULTIPLE) {
                    $flags[] = [
                        'key'   => 'bigger_than_order',
                        'label' => 'Added more than the order was worth',
                        'level' => 'danger',
                    ];
                } elseif ($amount >= $total - self::WHOLE_INVOICE_TOLERANCE) {
                    $flags[] = [
                        'key'   => 'whole_invoice',
                        'label' => 'Looks like the whole invoice was added — check the proofs',
                        'level' => 'danger',
                    ];
                }
            }

            if ($isGrant && $r->order_id && in_array((int) $r->order_id, $suspectOrders, true)) {
                $flags[] = [
                    'key'   => 'duplicate_proofs',
                    'label' => 'One transfer may have been counted twice',
                    'level' => 'danger',
                ];
            }

            if ($isGrant && $r->source === CustomerCreditModel::SOURCE_MANUAL && !$r->order_id) {
                $flags[] = [
                    'key'   => 'manual_no_order',
                    'label' => 'Typed in by hand, not linked to an order',
                    'level' => 'info',
                ];
            }

            // Shabib and Taimur approving their own entries is the designed
            // behaviour, not a finding — shown so the trail is complete, never
            // coloured as a problem.
            if ($r->created_by && $r->approved_by && (int) $r->created_by === (int) $r->approved_by) {
                $flags[] = [
                    'key'   => 'self_approved',
                    'label' => 'Added and approved by the same person',
                    'level' => 'info',
                ];
            }

            if ($r->status === CustomerCreditModel::STATUS_VOIDED) {
                $flags[] = ['key' => 'removed', 'label' => 'Removed', 'level' => 'muted'];
            }

            $out[(int) $r->id] = $flags;
        }

        return $out;
    }

    /**
     * Orders where the SAME transfer looks like it was reported more than once:
     * three or more payment signals of an identical amount, at least one of them
     * unpaired. This is the exact shape of the ref-less-bank-email defect that
     * put five whole invoices into the bucket in Aug-2026.
     *
     * @return array<int> order ids
     */
    private function ordersWithDuplicateProofs(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (empty($ids)) {
            return [];
        }

        try {
            $rows = DB::table('t_fin_payment_signal')
                ->whereIn('matched_order_id', $ids)
                ->whereIn('status', ['matched', 'amount_mismatch'])
                ->get(['matched_order_id', 'extracted_amount', 'paired_signal_id']);
        } catch (\Throwable $e) {
            return [];   // a diagnostic must never break the page
        }

        $byOrderAmount = [];
        foreach ($rows as $r) {
            $key = $r->matched_order_id . '|' . round((float) $r->extracted_amount, 2);
            $byOrderAmount[$key][] = $r;
        }

        $suspect = [];
        foreach ($byOrderAmount as $key => $group) {
            if (count($group) < 3) {
                continue;
            }
            $hasUnpaired = false;
            foreach ($group as $g) {
                if (empty($g->paired_signal_id)) {
                    $hasUnpaired = true;
                    break;
                }
            }
            if ($hasUnpaired) {
                $suspect[] = (int) explode('|', $key)[0];
            }
        }

        return array_values(array_unique($suspect));
    }

    /** Credit ids that a human should look at — counting rows only. */
    public function flaggedCreditIds(): array
    {
        if (!$this->credit->tableReady()) {
            return [];
        }

        $ids = DB::table('t_crm_customer_credit')
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES)
            ->where('entry_type', CustomerCreditModel::TYPE_GRANT)
            ->pluck('id')
            ->all();

        $flags = $this->flagsForCreditIds($ids);

        $out = [];
        foreach ($flags as $creditId => $list) {
            if (!empty(array_intersect(array_column($list, 'key'), self::REVIEW_FLAGS))) {
                $out[] = (int) $creditId;
            }
        }

        return $out;
    }

    public function flaggedCount(): int
    {
        return count($this->flaggedCreditIds());
    }

    /** Customers holding at least one entry that needs a look. */
    public function flaggedCustomerIds(): array
    {
        $ids = $this->flaggedCreditIds();
        if (empty($ids)) {
            return [];
        }

        return DB::table('t_crm_customer_credit')
            ->whereIn('id', $ids)
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // =====================================================================
    // Small batched lookups
    // =====================================================================

    private function applyActivityFilters($q, array $filters): void
    {
        if (!empty($filters['from'])) {
            $q->whereRaw('DATE(created_at) >= ?', [$filters['from']]);
        }
        if (!empty($filters['to'])) {
            $q->whereRaw('DATE(created_at) <= ?', [$filters['to']]);
        }
        if (!empty($filters['type']) && in_array($filters['type'], [
            CustomerCreditModel::TYPE_GRANT,
            CustomerCreditModel::TYPE_CONSUME,
            CustomerCreditModel::TYPE_ADJUST,
        ], true)) {
            $q->where('entry_type', $filters['type']);
        }
        if (!empty($filters['source'])) {
            $q->where('source', $filters['source']);
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'counting') {
                $q->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES);
            } else {
                $q->where('status', $filters['status']);
            }
        }
        if (!empty($filters['actor'])) {
            $actor = (int) $filters['actor'];
            $q->where(function ($w) use ($actor) {
                $w->where('created_by', $actor)
                  ->orWhere('approved_by', $actor)
                  ->orWhere('voided_by', $actor);
            });
        }
        if (!empty($filters['customer_id'])) {
            // Follow the merge chain: asking for a merged-away record must show
            // the money where it actually lives.
            $resolved = $this->credit->resolveCustomerId((int) $filters['customer_id']);
            $q->whereIn('customer_id', array_unique([(int) $filters['customer_id'], (int) $resolved]));
        }
        if (!empty($filters['order_id'])) {
            $q->where('order_id', (int) $filters['order_id']);
        }
    }

    /** Newest entry of one type per customer. */
    private function lastEntryPerCustomer(array $customerIds, string $type): array
    {
        if (empty($customerIds)) {
            return [];
        }

        $rows = DB::table('t_crm_customer_credit')
            ->whereIn('customer_id', $customerIds)
            ->where('entry_type', $type)
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES)
            ->orderBy('id')
            ->get(['id', 'customer_id', 'amount', 'order_id', 'created_by', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->customer_id] = $r;   // ascending id ⇒ the last write wins
        }

        return $out;
    }

    private function entryCountPerCustomer(array $customerIds): array
    {
        if (empty($customerIds)) {
            return [];
        }

        return DB::table('t_crm_customer_credit')
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->pluck(DB::raw('COUNT(*)'), 'customer_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function customerIdsWithAnyEntry(): array
    {
        return DB::table('t_crm_customer_credit')
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function customerIdsWithGrantBy(int $userId): array
    {
        return DB::table('t_crm_customer_credit')
            ->where('created_by', $userId)
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function customerIdsActiveSince(string $date): array
    {
        return DB::table('t_crm_customer_credit')
            ->whereRaw('DATE(created_at) >= ?', [$date])
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Balance after each entry, along one customer's own timeline. */
    private function runningBalances(int $customerId): array
    {
        $id = $this->credit->resolveCustomerId($customerId);
        if (!$id) {
            return [];
        }

        $rows = DB::table('t_crm_customer_credit')
            ->whereIn('customer_id', array_unique([$customerId, $id]))
            ->orderBy('id')
            ->get(['id', 'amount', 'status']);

        $running = 0.0;
        $out     = [];
        foreach ($rows as $r) {
            if (in_array($r->status, CustomerCreditModel::SPENDABLE_STATUSES, true)) {
                $running += (float) $r->amount;
            }
            $out[(int) $r->id] = round($running, 2);
        }

        return $out;
    }

    /** @return array<int, object{id:int,name:string,phone:string}> */
    private function customerRows(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach (DB::table('t_crm_prod_customer')->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'phone', 'customer_type']) as $c) {
            $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
            $out[(int) $c->id] = (object) [
                'id'    => (int) $c->id,
                'name'  => $name !== '' ? $name : ('Customer #' . $c->id),
                'phone' => (string) ($c->phone ?? ''),
                'type'  => $c->customer_type,
            ];
        }

        return $out;
    }

    /**
     * Order number AND invoice total, batched.
     *
     * ⭐ The total is what makes an entry readable: "added Rs 8,260.60" means
     * nothing on its own, but "added Rs 8,260.60 against a Rs 2,589.40 invoice"
     * tells a manager instantly that something is wrong. It is also what the
     * whole-invoice flag is computed from, so showing it puts the evidence for
     * the flag right next to the flag.
     *
     * @return array<int, array{number:?string,total:?float}>
     */
    private function orderInfo(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach (DB::table('t_crm_prod_order')->whereIn('id', $ids)->get(['id', 'order_number', 'total_price']) as $o) {
            $out[(int) $o->id] = [
                'number' => $o->order_number,
                'total'  => $o->total_price === null ? null : round((float) $o->total_price, 2),
            ];
        }

        return $out;
    }

    private function userNames(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($ids)) {
            return [];
        }

        return DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id')->all();
    }

    /**
     * Which bank actually holds the money, read from the ledger row.
     *
     * ⚠ NOT from t_crm_customer_credit.receiving_account_id — that column is
     * NULL on every production row so far (the overpay doors do not pass it),
     * while the ledger posting always names a real account. The ledger is the
     * honest answer; the column would print an empty column forever.
     */
    private function bankNamesForLedger(array $ledgerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ledgerIds))));
        if (empty($ids)) {
            return [];
        }

        $ledger = DB::table('t_fin_ledger')->whereIn('id', $ids)
            ->get(['id', 'transaction_type', 'from_account_id', 'to_account_id']);

        $accountIds = [];
        foreach ($ledger as $l) {
            // grant: CUSTOMER_CREDIT → bank, so the bank is the TO side.
            // consume/write-off: revenue → CUSTOMER_CREDIT, no bank at all.
            $accountIds[] = $l->transaction_type === CustomerCreditService::LEDGER_TYPE_GRANT
                ? $l->to_account_id
                : null;
        }

        $names = DB::table('t_fin_accounts')
            ->whereIn('id', array_filter($accountIds))
            ->pluck('account_name', 'id')
            ->all();

        $out = [];
        foreach ($ledger as $l) {
            if ($l->transaction_type !== CustomerCreditService::LEDGER_TYPE_GRANT) {
                continue;
            }
            $name = $names[$l->to_account_id] ?? null;
            if ($name) {
                $out[(int) $l->id] = $name;
            }
        }

        return $out;
    }

    // =====================================================================
    // Labels — one wording, used by page and CSV alike
    // =====================================================================

    private function typeLabel(object $r): string
    {
        return match ($r->entry_type) {
            CustomerCreditModel::TYPE_GRANT   => 'Added',
            CustomerCreditModel::TYPE_CONSUME => 'Used on an order',
            default                           => $r->source === CustomerCreditModel::SOURCE_ZERO_OUT
                                                    ? 'Cleared to zero'
                                                    : 'Adjusted',
        };
    }

    private function statusLabel(object $r): string
    {
        return match ($r->status) {
            CustomerCreditModel::STATUS_PENDING  => 'Awaiting approval',
            CustomerCreditModel::STATUS_RESERVED => 'Held for an order',
            CustomerCreditModel::STATUS_VOIDED   => 'Removed',
            default                              => 'Counted',
        };
    }

    private function sourceLabel(?string $source): string
    {
        return match ($source) {
            CustomerCreditModel::SOURCE_OVERPAYMENT  => 'Paid more than the order',
            CustomerCreditModel::SOURCE_CANCELLATION => 'Order cancelled',
            CustomerCreditModel::SOURCE_ZERO_OUT     => 'Written off',
            CustomerCreditModel::SOURCE_MANUAL       => 'Entered by hand',
            default                                  => (string) $source,
        };
    }

    private function displayDate($value): ?string
    {
        return $value ? date('d-M-Y', strtotime((string) $value)) : null;
    }

    private function displayDateTime($value): ?string
    {
        return $value ? date('d M Y, g:i A', strtotime((string) $value)) : null;
    }

    // =====================================================================
    // CSV
    // =====================================================================

    /** The activity log as CSV rows, for a manager who wants it in a sheet. */
    public function activityCsv(array $filters): array
    {
        $out = [[
            'Date', 'Time', 'Customer', 'Customer ID', 'What happened', 'Status',
            'Amount', 'Counts toward balance', 'Source', 'Order', 'Invoice amount', 'Implies customer paid', 'Bank',
            'Entered by', 'Approved by', 'Removed by', 'Reason', 'Needs review',
        ]];

        $page = 1;
        do {
            $chunk = $this->activity($filters, $page, 200);
            foreach ($chunk['rows'] as $r) {
                $out[] = [
                    $r['date'],
                    $r['date_full'],
                    $r['customer_name'],
                    $r['customer_id'],
                    $r['type_label'],
                    $r['status_label'],
                    number_format($r['amount'], 2, '.', ''),
                    $r['counts'] ? 'yes' : 'no',
                    $r['source_label'],
                    $r['order_number'] ?? '',
                    $r['order_total'] !== null ? number_format($r['order_total'], 2, '.', '') : '',
                    $r['implied_paid'] !== null ? number_format($r['implied_paid'], 2, '.', '') : '',
                    $r['bank'] ?? '',
                    $r['entered_by'] ?? '',
                    $r['approved_by'] ?? '',
                    $r['voided_by'] ?? '',
                    $r['reason'] ?? $r['voided_reason'] ?? '',
                    $r['needs_review'] ? 'YES' : '',
                ];
            }
            $page++;
        } while ($page <= ($chunk['last_page'] ?? 1) && $page <= 100);

        return $out;
    }
}
