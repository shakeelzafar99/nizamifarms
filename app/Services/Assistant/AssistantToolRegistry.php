<?php

namespace App\Services\Assistant;

use App\Models\FIN\AccountModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The NF Assistant's tool surface.
 *
 * ═══ THE ONE RULE ═══
 * READ tools may query. WRITE tools DO NOT WRITE — they produce a DRAFT row in
 * t_ai_drafts and stop. Money only ever moves when a human taps Confirm, which
 * replays the draft through the EXISTING endpoints (see AssistantDraftService).
 * So the LLM cannot post to the ledger, cannot skip an approval, and cannot
 * exceed a vendor balance — because it never reaches that code at all.
 *
 * Why that matters beyond "AI might hallucinate": a screenshot or a customer
 * message could contain text engineered to steer the model. With this design
 * the worst case is a wrong DRAFT, which Taimur sees spelled out on a card and
 * rejects. Prompt injection can't move money.
 *
 * PERMISSIONS: every tool re-checks the SAME mobile permission as the endpoint
 * it fronts (view_expenses, etc.), on top of the master `use_ai_assistant`
 * gate. The assistant can never become a privilege-escalation path — giving
 * someone the assistant does not give them anything their role lacks.
 *
 * See NF-ASSISTANT-AGENT-PLAN-JUL2026.md.
 */
class AssistantToolRegistry
{
    public function __construct(private AssistantDraftService $drafts)
    {
    }

    /**
     * Tool declarations in Gemini's functionDeclarations shape.
     * Types are UPPERCASE per Gemini's OpenAPI subset (STRING/NUMBER/INTEGER/
     * OBJECT/ARRAY/BOOLEAN) — lowercase is silently rejected.
     */
    public function declarations(): array
    {
        return [
            [
                'name' => 'get_context',
                'description' => 'Look up the reference data needed to record money: expense categories in use, payment source accounts, banks, business units, and the user\'s saved defaults. Call this FIRST when recording an expense or vendor payment and you do not already have the ids.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
            [
                'name' => 'find_vendor',
                'description' => 'Search vendors by name. Returns id, name, current outstanding balance, and the vendor\'s usual payment source. Use before drafting a vendor payment.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the vendor name, e.g. "malik" or "ghousia"'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_pending_draft',
                'description' => 'Check whether the user already has a confirmation card waiting. Call this when the user says yes/ok/confirm/theek hai, or asks to CHANGE something on a card — the result gives you the exact details to re-draft from.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
            [
                'name' => 'list_expenses',
                'description' => 'List recorded expenses (read-only): a month\'s total and recent items, optionally filtered by category. Use for questions like "how much fuel this month?" or "what did we spend yesterday?".',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'month' => ['type' => 'STRING', 'description' => 'YYYY-MM. Omit for the current month.'],
                        'category' => ['type' => 'STRING', 'description' => 'Optional category filter, e.g. Fuel'],
                    ],
                ],
            ],
            [
                'name' => 'find_order',
                'description' => 'Look up customer orders (read-only) by order number (e.g. SH-20931 or NF-18834) or by customer name/phone. Returns status, amount and payment info. Use for "what is the status of order X" or "did customer Y\'s order go out?".',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Order number, customer name, or phone'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'list_customer_invoices',
                'description' => 'List a CUSTOMER\'s invoices/bills (read-only), optionally within a date range and by status. Use for "show <customer>\'s bills from 1 to 15 July", "which invoices are pending for <shop>", "how much does <customer> owe this month". Works for shops (e.g. Sunny Kitchen) and regular customers. Call find_customer first for a real customer_id. Returns each invoice (order number, date, total, paid, balance, status) plus totals. Dates filter by ORDER DATE — say that in your reply.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'customer_id' => ['type' => 'INTEGER', 'description' => 'From find_customer'],
                        'from_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD inclusive. Omit for no lower bound.'],
                        'to_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD inclusive. Omit for no upper bound.'],
                        'status' => ['type' => 'STRING', 'description' => 'open (unpaid/partial), paid, or all. Default open.'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
            [
                'name' => 'set_default',
                'description' => 'Remember a preference for this user so you never ask again (e.g. their usual payment source for expenses). Only use after the user has told you what they want. Pass the NAME the user said (e.g. "expense fund") or an exact id from get_context — NEVER an id you have not seen. The response tells you what was actually saved; repeat that name back to the user.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'key' => ['type' => 'STRING', 'description' => 'One of: expense_payment_source_account_id, expense_business_unit_id, vendor_payment_source_account_id, expense_receiving_account_id (default BANK for expenses), vendor_payment_receiving_account_id (default BANK for vendor payments)'],
                        'value' => ['type' => 'STRING', 'description' => 'The account/bank/business-unit NAME the user said, or an exact id from get_context'],
                    ],
                    'required' => ['key', 'value'],
                ],
            ],
            [
                'name' => 'draft_expense',
                'description' => 'Prepare an expense for the user to confirm. This does NOT record anything — it shows them a confirmation card. Always call get_context first so the ids are real. If you are missing the payment source or business unit and the user has no saved default, ASK them instead of guessing.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'amount' => ['type' => 'NUMBER', 'description' => 'Amount in PKR'],
                        'expense_category' => ['type' => 'STRING', 'description' => 'Free-text category, e.g. Fuel, Transportation, Rent, Office Supplies'],
                        'title' => ['type' => 'STRING', 'description' => 'Short title. Defaults to the category if omitted.'],
                        'description' => ['type' => 'STRING', 'description' => 'Optional detail'],
                        'payment_source_account_id' => ['type' => 'INTEGER', 'description' => 'Account id the money comes from (from get_context)'],
                        'receiving_account_id' => ['type' => 'INTEGER', 'description' => 'Which bank it was paid from, when the source is a bank and the user said so. If unknown, OMIT it — the card will show bank buttons for one-tap selection. Do not ask in text.'],
                        'business_unit_id' => ['type' => 'INTEGER', 'description' => 'Business unit id (from get_context)'],
                        'expense_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD. Omit for today. Cannot be in the future.'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a card the user already has ("make it 4,000"), pass that draft_id from get_pending_draft — the old card is then cancelled so he cannot confirm the wrong one by scrolling up.'],
                    ],
                    'required' => ['amount', 'expense_category'],
                ],
            ],
            [
                'name' => 'draft_vendor_payment',
                'description' => 'Prepare a payment to a vendor for the user to confirm. This does NOT pay anything — it shows them a confirmation card. Call find_vendor first to get a real vendor_id.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'vendor_id' => ['type' => 'INTEGER', 'description' => 'From find_vendor'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Amount in PKR'],
                        'payment_source_account_id' => ['type' => 'INTEGER', 'description' => 'Account the money comes from (from get_context)'],
                        'receiving_account_id' => ['type' => 'INTEGER', 'description' => 'Which of our banks the payment goes from, when the source is a bank and the user said so. If unknown, OMIT it — the card will show bank buttons for one-tap selection. Do not ask in text.'],
                        'transaction_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD. Omit for today.'],
                        'description' => ['type' => 'STRING', 'description' => 'Optional note'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a card the user already has, pass that draft_id from get_pending_draft — the old card is cancelled so it cannot be confirmed by mistake.'],
                    ],
                    'required' => ['vendor_id', 'amount'],
                ],
            ],
            [
                'name' => 'find_customer',
                'description' => 'Look up a CUSTOMER by name. Returns matches with customer_type (regular|shop), each regular customer\'s open online orders, and each SHOP\'s open invoices + outstanding total. Call this before draft_payment_proof OR draft_shop_payment — never guess a customer_id, and pick the draft tool by customer_type.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Customer name (or part of it)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'draft_payment_proof',
                'description' => 'Prepare a customer PAYMENT PROOF for confirmation — it attaches to the customer\'s open online order via the SAME matcher used for WhatsApp proofs, and shows up in Online Approvals as "proof received". Use when the user says a customer PAID / forwards a payment screenshot and names the customer. Call find_customer first for a real customer_id. If a screenshot is attached, READ the amount off it and pass it. If no screenshot, pass the amount the user said. OMIT amount ONLY when the customer has exactly one open order (it will assume the full amount). This does NOT verify the payment — verification still needs a bank confirmation.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'customer_id' => ['type' => 'INTEGER', 'description' => 'From find_customer'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Payment amount in PKR — from the screenshot if attached, else what the user said. Omit only if the customer has a single open order.'],
                        'reference' => ['type' => 'STRING', 'description' => 'Transaction reference from the screenshot, if visible.'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a proof card the user already has, pass that draft_id from get_pending_draft — the old card is cancelled so it cannot be confirmed by mistake.'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
            [
                'name' => 'draft_shop_payment',
                'description' => 'Record money RECEIVED from a SHOP customer (customer_type=shop in find_customer, e.g. Table Talk). Shows a confirmation card; on confirm it posts REAL payments to the shop\'s open invoices oldest-first (FIFO) — the exact same rule and service as the web Shop tab. Use when the user says a SHOP paid / sent money. NEVER use draft_payment_proof for a shop (it will refuse). The amount is required and must not exceed the shop\'s outstanding total.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'customer_id' => ['type' => 'INTEGER', 'description' => 'From find_customer — must be a shop'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Amount received in PKR'],
                        'reference' => ['type' => 'STRING', 'description' => 'Bank transaction reference, if the user gave one.'],
                        'receiving_account_id' => ['type' => 'INTEGER', 'description' => 'Which of OUR banks received it, if the user named one. Omit to let the card offer a one-tap picker.'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a card the user already has, pass that draft_id from get_pending_draft — the old card is cancelled so it cannot be confirmed by mistake.'],
                    ],
                    'required' => ['customer_id', 'amount'],
                ],
            ],
            [
                'name' => 'draft_account_transfer',
                'description' => 'Move money between OUR OWN accounts (e.g. "move 50,000 from Online bank to Cash", "HBL to Meezan"). Shows a confirmation card; on confirm it records a transfer through the SAME web flow — an ONLINE transfer (touching a bank) goes to the approval queue, a cash-to-cash move posts immediately. Call get_context first for the account ids (use is_bank to know which touch a bank). NOT for correcting the bank on an already-recorded payment — this only creates a NEW transfer.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'from_account_id' => ['type' => 'INTEGER', 'description' => 'Source account id from get_context'],
                        'to_account_id' => ['type' => 'INTEGER', 'description' => 'Destination account id from get_context (must differ)'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Amount in PKR'],
                        'mode' => ['type' => 'STRING', 'description' => 'cash or online. Omit to auto-pick (bank-touching = online → approval; else cash).'],
                        'receiving_account_id' => ['type' => 'INTEGER', 'description' => 'Which of OUR banks it goes through (from get_context banks) — needed when a bank is touched. Omit to let the card offer a picker.'],
                        'transaction_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD. Omit for today.'],
                        'description' => ['type' => 'STRING', 'description' => 'Optional note.'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a card the user already has, pass that draft_id from get_pending_draft — the old card is cancelled.'],
                    ],
                    'required' => ['from_account_id', 'to_account_id', 'amount'],
                ],
            ],
            [
                'name' => 'draft_vendor_purchase',
                'description' => 'Prepare a vendor PURCHASE (stock/goods bought from a vendor — increases what we owe them; no money moves). Shows a confirmation card. Call find_vendor first for the real vendor_id. Use this when the user says "purchased/bought/khareeda", NOT for paying a vendor.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'vendor_id' => ['type' => 'INTEGER', 'description' => 'From find_vendor'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Purchase amount in PKR'],
                        'transaction_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD. Omit for today.'],
                        'description' => ['type' => 'STRING', 'description' => 'Optional note, e.g. what was bought'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a card the user already has, pass that draft_id from get_pending_draft — the old card is cancelled so it cannot be confirmed by mistake.'],
                    ],
                    'required' => ['vendor_id', 'amount'],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call. Returns an array that becomes the functionResponse.
     * NEVER throws to the caller — a tool failure must come back to the model
     * as data ({error: ...}) so it can recover or explain, not blow up the turn.
     */
    public function call(string $name, array $args, $user): array
    {
        try {
            return match ($name) {
                'get_context'          => $this->getContext($user),
                'get_pending_draft'    => $this->getPendingDraft($user),
                'find_vendor'          => $this->findVendor($args, $user),
                'find_customer'        => $this->findCustomer($args, $user),
                'list_expenses'        => $this->listExpenses($args, $user),
                'find_order'           => $this->findOrder($args, $user),
                'list_customer_invoices' => $this->listCustomerInvoices($args, $user),
                'set_default'          => $this->setDefault($args, $user),
                'draft_expense'         => $this->drafts->draftExpense($args, $user),
                'draft_vendor_payment'  => $this->drafts->draftVendorPayment($args, $user),
                'draft_vendor_purchase' => $this->drafts->draftVendorPurchase($args, $user),
                'draft_shop_payment'    => $this->drafts->draftShopPayment($args, $user),
                'draft_account_transfer' => $this->drafts->draftAccountTransfer($args, $user),
                'draft_payment_proof'   => $this->drafts->draftPaymentProof($args, $user),
                default                => ['error' => "Unknown tool: {$name}"],
            };
        } catch (\Throwable $e) {
            Log::error('Assistant tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['error' => 'That lookup failed: ' . $e->getMessage()];
        }
    }

    // ── READ TOOLS ───────────────────────────────────────────────────────────

    /**
     * Everything the model needs to turn "record 5000 fuel" into real ids.
     * One call instead of four: fewer round trips = faster + cheaper, and the
     * model can't half-populate a draft from a partial picture.
     */
    private function getContext($user): array
    {
        $accounts = DB::table('t_fin_accounts')
            ->where('is_active', 1)
            ->whereIn('account_category', ['cash', 'bank'])
            ->get(['id', 'account_code', 'account_name', 'account_category', 'is_private'])
            ->filter(fn($a) => !$a->is_private || $this->isTaimur($user)) // private accounts: Taimur-only, same as RequestController
            ->map(fn($a) => array_filter([
                'id' => $a->id,
                'code' => $a->account_code,
                'name' => $a->account_name,
                'is_bank' => $a->account_category === 'bank',
                // Owner ruling: qurbani is a once-a-year flow. Flag the rows so
                // a plain "online"/"cash" can never land on a QURBANI_* account.
                'qurbani_only' => $this->mentionsQurbani($a->account_name . ' ' . $a->account_code) ?: null,
            ], fn($v) => $v !== null))->values()->all();

        $banks = DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'short_code'])
            ->map(fn($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->short_code])
            ->all();

        $units = DB::table('t_fin_business_units')
            ->where('is_active', 1)
            ->get(['id', 'name', 'code'])
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'code' => $u->code])
            ->all();

        // Real categories people actually use, so the model reuses existing
        // spellings instead of inventing "Petrol" alongside "Fuel/Petrol".
        $categories = DB::table('t_req_master')
            ->whereNotNull('expense_category')
            ->where('expense_category', '!=', '')
            ->select('expense_category', DB::raw('COUNT(*) c'))
            ->groupBy('expense_category')
            ->orderByDesc('c')
            ->limit(25)
            ->pluck('expense_category')
            ->all();

        return [
            'payment_source_accounts' => $accounts,
            'banks' => $banks,
            'business_units' => $units,
            'common_expense_categories' => $categories,
            'saved_defaults' => $this->prefs($user->id),
            'today' => now()->toDateString(),
            'note' => 'Staff salaries cannot be recorded as an expense — they must go through the Payroll screen. '
                . 'Accounts flagged qurbani_only are for the once-a-year Qurbani flow ONLY — never use them unless the user literally says "qurbani".',
        ];
    }

    private function findVendor(array $args, $user): array
    {
        $q = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($q) < 2) {
            return ['error' => 'Give me at least 2 characters of the vendor name.'];
        }

        // Space-insensitive: "Lacarne" must find "LaCarne" and "La Carne" —
        // voice transcripts and thumb-typing don't respect the stored spacing.
        $norm = mb_strtolower(str_replace(' ', '', $q));
        $rows = DB::table('t_fin_vendors as v')
            ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'v.account_id')
            ->where('v.is_active', 1)
            ->where(function ($w) use ($q, $norm) {
                $w->where('v.vendor_name', 'like', '%' . $q . '%')
                  ->orWhereRaw("LOWER(REPLACE(v.vendor_name, ' ', '')) LIKE ?", ['%' . $norm . '%']);
            })
            ->limit(8)
            ->get(['v.id', 'v.vendor_name', 'v.business_unit_id', 'v.default_payment_source_id', 'a.current_balance']);

        if ($rows->isEmpty()) {
            // A dead-end question loops (observed live: voice heard "Laqarni
            // Chicken" for LaCarne and the chat went in circles). Offer the
            // closest real names instead so the next turn can resolve it.
            $closest = DB::table('t_fin_vendors')
                ->where('is_active', 1)
                ->get(['id', 'vendor_name'])
                ->map(function ($v) use ($norm) {
                    similar_text($norm, mb_strtolower(str_replace(' ', '', $v->vendor_name)), $pct);
                    return ['id' => $v->id, 'name' => $v->vendor_name, 'pct' => $pct];
                })
                ->filter(fn($v) => $v['pct'] >= 45)
                ->sortByDesc('pct')
                ->take(3)
                ->map(fn($v) => ['id' => $v['id'], 'name' => $v['name']])
                ->values()
                ->all();

            return [
                'vendors' => [],
                'closest_matches' => $closest,
                'note' => $closest
                    ? 'No vendor matches that name exactly. Ask the user if they meant one of closest_matches (e.g. "Did you mean LaCarne?") — do NOT assume.'
                    : 'No vendor matches that name and nothing similar exists. Ask the user for the correct name — do not invent one.',
            ];
        }

        return [
            'vendors' => $rows->map(fn($v) => [
                'id' => $v->id,
                'name' => $v->vendor_name,
                // The payable balance — the same number the Vendors screen shows.
                'outstanding_balance' => round((float) ($v->current_balance ?? 0), 2),
                'business_unit_id' => $v->business_unit_id,
                'usual_payment_source_account_id' => $v->default_payment_source_id,
            ])->all(),
            'note' => 'A payment cannot exceed outstanding_balance — the server rejects that.',
        ];
    }

    /**
     * Read-only expense summary from t_req_master — the same rows the Expenses
     * screen shows. Returns a month total + recent items, small enough to keep
     * the model's context (and cost) down.
     */
    private function listExpenses(array $args, $user): array
    {
        $month = (string) ($args['month'] ?? now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        $q = DB::table('t_req_master')
            ->whereNotNull('expense_category')
            ->where('amount', '>', 0)
            ->whereIn('status', ['approved', 'pending', 'pending_l1', 'pending_l2'])
            ->whereBetween(DB::raw('COALESCE(expense_date, DATE(created_at))'), [$start, $end]);

        $category = trim((string) ($args['category'] ?? ''));
        if ($category !== '') {
            $q->where('expense_category', 'like', '%' . $category . '%');
        }

        $total = (clone $q)->sum('amount');
        $count = (clone $q)->count();
        $items = (clone $q)->orderByDesc(DB::raw('COALESCE(expense_date, DATE(created_at))'))
            ->limit(12)
            ->get(['title', 'expense_category', 'amount', 'expense_date', 'status', 'created_at'])
            ->map(fn($r) => [
                'date' => $r->expense_date ?: substr((string) $r->created_at, 0, 10),
                'title' => $r->title,
                'category' => $r->expense_category,
                'amount' => (float) $r->amount,
                'status' => $r->status,
            ])->all();

        $out = [
            'month' => $month,
            'category_filter' => $category ?: null,
            'total' => round((float) $total, 2),
            'entry_count' => $count,
            'recent_items' => $items,
            'note' => $count > 12 ? 'Showing the 12 most recent of ' . $count . ' entries.' : null,
        ];

        // Self-correction: staff type categories like "Petrol" while users say
        // "fuel". A zero-hit filter is far more likely a naming mismatch than a
        // real zero — hand the model the month's ACTUAL category names so its
        // next call uses the right one instead of reporting a false Rs 0.
        if ($category !== '' && $count === 0) {
            $out['available_categories_this_month'] = DB::table('t_req_master')
                ->whereNotNull('expense_category')
                ->where('amount', '>', 0)
                ->whereBetween(DB::raw('COALESCE(expense_date, DATE(created_at))'), [$start, $end])
                ->distinct()->orderBy('expense_category')
                ->pluck('expense_category')->all();
            $out['note'] = 'No entries under "' . $category . '". Check available_categories_this_month for the closest real category (e.g. users say fuel, the books may say Petrol) and call again with that — or tell the user there is genuinely nothing.';
        }

        return $out;
    }

    /**
     * Resolve a CUSTOMER by name for payment proofs. Space-insensitive, with a
     * per-candidate open-order count so the model knows whether there is
     * anything to attach a proof to. Same closest_matches self-correction as
     * find_vendor for ASR/typo mangling.
     */
    private function findCustomer(array $args, $user): array
    {
        $q = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($q) < 2) {
            return ['error' => 'Give me at least 2 characters of the customer name.'];
        }
        $norm = mb_strtolower(str_replace(' ', '', $q));

        $rows = DB::table('t_crm_prod_customer')
            ->where(function ($w) use ($q, $norm) {
                $w->whereRaw("LOWER(REPLACE(CONCAT(COALESCE(first_name,''),COALESCE(last_name,'')),' ','')) LIKE ?", ['%' . $norm . '%'])
                  ->orWhere('first_name', 'like', '%' . $q . '%')
                  ->orWhere('last_name', 'like', '%' . $q . '%')
                  ->orWhere('phone_original', 'like', '%' . $q . '%');
            })
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'phone_original', 'customer_type', 'company']);

        if ($rows->isEmpty()) {
            $closest = DB::table('t_crm_prod_customer')
                ->get(['id', 'first_name', 'last_name'])
                ->map(function ($c) use ($norm) {
                    $full = mb_strtolower(str_replace(' ', '', ($c->first_name ?? '') . ($c->last_name ?? '')));
                    similar_text($norm, $full, $pct);
                    return ['id' => $c->id, 'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')), 'pct' => $pct];
                })
                ->filter(fn($c) => $c['pct'] >= 55)
                ->sortByDesc('pct')->take(3)
                ->map(fn($c) => ['id' => $c['id'], 'name' => $c['name']])->values()->all();

            return [
                'customers' => [],
                'closest_matches' => $closest,
                'note' => $closest
                    ? 'No exact match. Ask if they meant one of closest_matches — do NOT assume.'
                    : 'No customer matches that name. Ask the user to check the name.',
            ];
        }

        // Count only orders a proof could actually attach to (owner ruling
        // 2026-07-19): those whose INVOICE is still awaiting approval — i.e.
        // what he sees in Online Approvals — WITH a balance. This is what
        // draft_payment_proof will scope to, so the number agrees.
        $customers = $rows->map(function ($c) {
            $isShop = ($c->customer_type ?? 'regular') === 'shop';
            $row = [
                'id' => $c->id,
                'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))
                    ?: ($c->company ?: ('Customer #' . $c->id)),
                'phone' => $c->phone_original,
                'customer_type' => $isShop ? 'shop' : 'regular',
            ];

            if ($isShop) {
                // SHOPS: their money goes through draft_shop_payment against the
                // Shop-tab invoice set (delivered, online, not booked under the
                // regular flow, balance remaining) — surface count + total so
                // the model can sanity-check the amount before drafting.
                $onlineMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];
                $open = DB::table('t_crm_prod_order as o')
                    ->where('o.customer_id', $c->id)
                    ->whereIn('o.payment_method', $onlineMethods)
                    ->where('o.order_status', 'delivered')
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('t_fin_ledger')
                            ->whereColumn('t_fin_ledger.order_id', 'o.id')
                            ->where('t_fin_ledger.transaction_type', 'invoice')
                            ->whereIn('t_fin_ledger.approval_status', ['approved', 'pending_l2']);
                    })
                    ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
                    ->selectRaw('COUNT(*) AS c, COALESCE(SUM(o.total_price - COALESCE(o.total_paid,0)),0) AS total')
                    ->first();
                $row['shop_open_invoices'] = (int) ($open->c ?? 0);
                $row['shop_outstanding'] = round((float) ($open->total ?? 0), 2);
            } else {
                $open = DB::table('t_fin_ledger as l')
                    ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                    ->where('o.customer_id', $c->id)
                    ->where('l.transaction_type', 'invoice')
                    ->whereIn('l.approval_status', ['pending', 'pending_l1', 'pending_l2'])
                    ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
                    ->distinct()->count('l.order_id');
                $row['open_orders'] = $open;
            }

            return $row;
        })->all();

        return [
            'customers' => $customers,
            'note' => 'open_orders is how many unpaid online orders each has. A proof needs at least one. If several customers match, ask which.',
        ];
    }

    /**
     * Read-only invoice list for ONE customer, optional date range + status.
     *
     * ⚠️ Dates filter on order_date (a REAL column). delivery_date is an
     * Eloquent ACCESSOR derived from status history — never usable in a WHERE.
     * The reply must say the range is by order date so a shop owner isn't
     * surprised. Balance = total_price − total_paid (the same figures the
     * approvals/shop screens show). Live orders only (collision rule).
     */
    private function listCustomerInvoices(array $args, $user): array
    {
        $customerId = (int) ($args['customer_id'] ?? 0);
        $customer = $customerId
            ? DB::table('t_crm_prod_customer')->where('id', $customerId)
                ->first(['id', 'first_name', 'last_name', 'company', 'customer_type'])
            : null;
        if (!$customer) {
            return ['error' => 'That customer id does not exist. Use find_customer first — never guess a customer id.'];
        }
        $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
            ?: ($customer->company ?: ('Customer #' . $customerId));

        $status = strtolower(trim((string) ($args['status'] ?? 'open')));
        if (!in_array($status, ['open', 'paid', 'all'], true)) {
            $status = 'open';
        }
        $from = $this->cleanDateArg($args['from_date'] ?? null);
        $to   = $this->cleanDateArg($args['to_date'] ?? null);

        $q = DB::table('t_crm_prod_order')
            ->where('customer_id', $customerId)
            // A cancelled order is not a bill — its leftover balance must never
            // show as "pending" (review catch, Jul-22).
            ->where('order_status', '!=', 'cancelled')
            ->when($from, fn ($w) => $w->whereDate('order_date', '>=', $from))
            ->when($to, fn ($w) => $w->whereDate('order_date', '<=', $to));

        // "open" = still owes money; "paid" = nothing outstanding. Uses the same
        // total_price/total_paid the approvals + shop screens use, so the number
        // agrees with what he sees there.
        if ($status === 'open') {
            $q->whereRaw('(total_price - COALESCE(total_paid,0)) > 0.01');
        } elseif ($status === 'paid') {
            $q->whereRaw('(total_price - COALESCE(total_paid,0)) <= 0.01');
        }

        $rows = $q->orderByDesc('order_date')->orderByDesc('id')->limit(200)
            ->get(['order_number', 'order_date', 'order_status', 'payment_status', 'total_price', 'total_paid']);

        $invoices = $rows->map(fn ($o) => [
            'order_number' => $o->order_number,
            'date'         => $o->order_date ? substr((string) $o->order_date, 0, 10) : null,
            'total'        => round((float) $o->total_price, 0),
            'paid'         => round((float) ($o->total_paid ?? 0), 0),
            'balance'      => round((float) $o->total_price - (float) ($o->total_paid ?? 0), 0),
            'status'       => $o->payment_status ?: 'unpaid',
        ])->all();

        return [
            'customer'       => $name,
            'customer_type'  => ($customer->customer_type ?? 'regular') === 'shop' ? 'shop' : 'regular',
            'date_basis'     => 'order_date',
            'from_date'      => $from,
            'to_date'        => $to,
            'status_filter'  => $status,
            'count'          => count($invoices),
            'total_billed'   => round($rows->sum(fn ($o) => (float) $o->total_price), 0),
            'total_outstanding' => round($rows->sum(fn ($o) => (float) $o->total_price - (float) ($o->total_paid ?? 0)), 0),
            'invoices'       => $invoices,
            'note'           => count($invoices) >= 200
                ? 'Showing the first 200 — narrow the date range for the rest.'
                : 'Range is by ORDER DATE. Tell the user that basis.',
        ];
    }

    /** A YYYY-MM-DD date arg, or null if empty/unparseable (never throws). */
    private function cleanDateArg($value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read-only order lookup.
     *
     * ⚠️ COLLISION RULE (workspace golden rule #4): t_crm_shopify_order and
     * t_crm_prod_order have overlapping numeric ids. This tool therefore only
     * ever touches the LIVE orders table and only ever resolves by
     * order_number / customer fields — never by a bare numeric id.
     */
    private function findOrder(array $args, $user): array
    {
        $q = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($q) < 3) {
            return ['error' => 'Give me an order number or at least 3 characters of the customer name.'];
        }

        $base = DB::table('t_crm_prod_order as o')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->select([
                'o.order_number', 'o.order_status', 'o.payment_status', 'o.payment_method',
                'o.total_price', 'o.order_date',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as customer_name"),
            ]);

        // Looks like an order number (has digits)? Try that first, with and
        // without the SH-/NF- prefix the user may or may not have typed.
        $digits = preg_replace('/\D/', '', $q);
        $rows = collect();
        if ($digits !== '' && mb_strlen($digits) >= 3) {
            $rows = (clone $base)
                ->where(function ($w) use ($q, $digits) {
                    $w->where('o.order_number', 'like', '%' . $q . '%')
                      ->orWhere('o.order_number', 'like', '%' . $digits);
                })
                ->orderByDesc('o.id')->limit(5)->get();
        }
        if ($rows->isEmpty()) {
            $rows = (clone $base)
                ->where(function ($w) use ($q) {
                    $w->where('c.first_name', 'like', '%' . $q . '%')
                      ->orWhere('c.last_name', 'like', '%' . $q . '%')
                      ->orWhere('c.phone_original', 'like', '%' . $q . '%');
                })
                ->orderByDesc('o.id')->limit(5)->get();
        }

        if ($rows->isEmpty()) {
            return ['orders' => [], 'note' => 'No matching order. Ask the user to check the order number or name.'];
        }

        return [
            'orders' => $rows->map(fn($o) => [
                'order_number' => $o->order_number,
                'customer' => $o->customer_name ?: null,
                'status' => $o->order_status,
                'payment' => trim(($o->payment_method ?? '') . ' / ' . ($o->payment_status ?? '')),
                'total' => (float) $o->total_price,
                'date' => substr((string) $o->order_date, 0, 16),
            ])->all(),
        ];
    }

    /**
     * The user's newest live confirmation card, with its full payload — so a
     * "yes" can be answered honestly and a "change the bank" can re-draft from
     * exact stored values instead of the model's own prose.
     */
    private function getPendingDraft($user): array
    {
        $d = DB::table('t_ai_drafts')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->where(function ($w) {
                $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        if (!$d) {
            return [
                'pending_draft' => null,
                'note' => 'No card is waiting. If the user just agreed to something, create the draft now with the draft tool — do NOT claim a card exists.',
            ];
        }

        return [
            'pending_draft' => [
                'draft_id' => $d->id,
                'type' => $d->type,
                'summary' => $d->summary,
                'details' => json_decode($d->payload_json, true),
                'awaiting_bank_choice' => str_contains((string) $d->payload_json, '_pending_choice'),
            ],
            'note' => 'A card IS waiting on screen. Confirmation happens by TAPPING the Confirm button on it — a chat "yes" does nothing. Tell the user to tap Confirm on the card (or pick the bank on it first if awaiting_bank_choice). To CHANGE something, call the draft tool again with the corrected details.',
        ];
    }

    // ── PREFERENCES (owner ruling: Taimur sets his own defaults) ─────────────

    private function setDefault(array $args, $user): array
    {
        $key = (string) ($args['key'] ?? '');
        $allowed = config('assistant.pref_keys', []);
        if (!in_array($key, $allowed, true)) {
            return ['error' => 'Unknown preference. Allowed: ' . implode(', ', $allowed)];
        }

        // ⚠️ RESOLVE, never trust. Found live: told "use expense fund", the
        // model called set_default with an INVENTED id (1 = NF Cash) and then
        // *said* "Expense Fund" — a silent wrong-account default. So the value
        // is resolved here against the real tables (by id or by name), and the
        // resolved NAME is echoed back so the model repeats the truth.
        $value = trim((string) ($args['value'] ?? ''));
        if ($value === '') {
            return ['error' => 'No value given.'];
        }

        // Order matters: receiving keys also end in _account_id.
        if (str_contains($key, '_receiving_')) {
            $resolved = $this->resolveReceivingBank($value);
        } elseif (str_ends_with($key, '_account_id')) {
            $resolved = $this->resolveAccount($value, $user);
        } else { // expense_business_unit_id
            $resolved = $this->resolveBusinessUnit($value);
        }
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $prefs = $this->prefs($user->id);
        $prefs[$key] = (string) $resolved['id'];

        DB::table('t_ai_user_prefs')->updateOrInsert(
            ['user_id' => $user->id],
            ['prefs_json' => json_encode($prefs), 'updated_at' => now()]
        );

        return [
            'saved' => true,
            'key' => $key,
            'resolved_to' => $resolved['name'],
            'note' => 'Tell the user exactly this name ("' . $resolved['name'] . '") so they can catch a wrong match.',
        ];
    }

    /** Resolve an account by exact id or fuzzy name/code. Cash+bank only. */
    private function resolveAccount(string $value, $user): array
    {
        $q = DB::table('t_fin_accounts')
            ->where('is_active', 1)
            ->whereIn('account_category', ['cash', 'bank']);
        if (!$this->isTaimur($user)) {
            $q->where('is_private', 0);
        }

        if (ctype_digit($value)) {
            $a = (clone $q)->where('id', (int) $value)->first(['id', 'account_name']);
            return $a
                ? ['id' => $a->id, 'name' => $a->account_name]
                : ['error' => 'No payment account has id ' . $value . '. Use get_context for real ids, or pass the account name.'];
        }

        $hits = (clone $q)
            ->where(function ($w) use ($value) {
                $w->where('account_name', 'like', '%' . $value . '%')
                  ->orWhere('account_code', 'like', '%' . str_replace(' ', '_', $value) . '%');
            })
            // Owner ruling (2026-07-19): "online" means the REGULAR (non-Qurbani)
            // account unless "qurbani" is actually said. Qurbani is a once-a-year
            // flow and must never shadow the daily one — otherwise "pay from
            // online" reads as ambiguous (Online Bank vs Qurbani Online). So
            // unless the user's word is qurbani-flavoured, drop QURBANI_* rows.
            ->when(!$this->mentionsQurbani($value), fn($w) => $w
                ->where('account_name', 'not like', '%qurbani%')
                ->where('account_code', 'not like', '%QURBANI%'))
            ->limit(5)
            ->get(['id', 'account_name']);

        if ($hits->count() === 1) {
            return ['id' => $hits[0]->id, 'name' => $hits[0]->account_name];
        }
        if ($hits->isEmpty()) {
            return ['error' => 'No account matches "' . $value . '". Call get_context and ask the user which one they mean.'];
        }
        return ['error' => 'Ambiguous — matches: ' . $hits->map(fn($h) => $h->account_name . ' (id ' . $h->id . ')')->implode(', ') . '. Ask the user which one.'];
    }

    /** Resolve one of OUR banks (t_fin_online_receiving_accounts) by id or name/short-code. */
    private function resolveReceivingBank(string $value): array
    {
        $q = DB::table('t_fin_online_receiving_accounts')->where('is_active', 1);

        if (ctype_digit($value)) {
            $b = (clone $q)->where('id', (int) $value)->first(['id', 'name']);
            return $b
                ? ['id' => $b->id, 'name' => $b->name]
                : ['error' => 'No bank has id ' . $value . '. Use get_context for the bank list.'];
        }

        $hits = (clone $q)
            ->where(function ($w) use ($value) {
                $w->where('name', 'like', '%' . $value . '%')
                  ->orWhere('short_code', 'like', '%' . $value . '%');
            })
            // Same qurbani ruling as resolveAccount — regular banks only unless
            // the user actually said "qurbani".
            ->when(!$this->mentionsQurbani($value), fn($w) => $w->where('name', 'not like', '%qurbani%'))
            ->limit(5)
            ->get(['id', 'name']);

        if ($hits->count() === 1) {
            return ['id' => $hits[0]->id, 'name' => $hits[0]->name];
        }
        if ($hits->isEmpty()) {
            return ['error' => 'No bank matches "' . $value . '". Call get_context and ask which one they mean.'];
        }
        return ['error' => 'Ambiguous — matches: ' . $hits->map(fn($h) => $h->name . ' (id ' . $h->id . ')')->implode(', ') . '. Ask the user which one.'];
    }

    /** Resolve a business unit by exact id or fuzzy name/code. */
    private function resolveBusinessUnit(string $value): array
    {
        $q = DB::table('t_fin_business_units')->where('is_active', 1);

        if (ctype_digit($value)) {
            $b = (clone $q)->where('id', (int) $value)->first(['id', 'name']);
            return $b
                ? ['id' => $b->id, 'name' => $b->name]
                : ['error' => 'No business unit has id ' . $value . '.'];
        }

        $hits = (clone $q)
            ->where(function ($w) use ($value) {
                $w->where('name', 'like', '%' . $value . '%')
                  ->orWhere('code', 'like', '%' . $value . '%');
            })
            ->limit(5)
            ->get(['id', 'name']);

        if ($hits->count() === 1) {
            return ['id' => $hits[0]->id, 'name' => $hits[0]->name];
        }
        if ($hits->isEmpty()) {
            return ['error' => 'No business unit matches "' . $value . '".'];
        }
        return ['error' => 'Ambiguous — matches: ' . $hits->map(fn($h) => $h->name . ' (id ' . $h->id . ')')->implode(', ') . '. Ask the user which one.'];
    }

    private function prefs(int $userId): array
    {
        $row = DB::table('t_ai_user_prefs')->where('user_id', $userId)->value('prefs_json');
        return $row ? (json_decode($row, true) ?: []) : [];
    }

    /** Did the user actually invoke the once-a-year Qurbani flow by name? */
    private function mentionsQurbani(string $value): bool
    {
        return stripos($value, 'qurbani') !== false || stripos($value, 'qurbaani') !== false;
    }

    /**
     * Private accounts are Taimur-only in RequestController@store. Mirror that
     * here so the model is never shown an account the server would reject.
     */
    private function isTaimur($user): bool
    {
        try {
            return $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
