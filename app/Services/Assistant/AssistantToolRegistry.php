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
                'name' => 'read_transfer_screenshot',
                'description' => 'Identify a bank TRANSFER screenshot: which way the money went, and who the other side is. Call this whenever the user sends a transfer/receipt image, BEFORE deciding what to record — pass exactly what you can read off it. Returns direction (out = we paid someone, in = a customer paid us, unknown = you must ask) and, when the account is one we have been taught, the vendor/account it belongs to.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'receiver_account' => ['type' => 'STRING', 'description' => 'Beneficiary account exactly as shown, e.g. "**** ...4237" or "PK16FAYSxx564"'],
                        'receiver_name'    => ['type' => 'STRING', 'description' => 'Beneficiary / "Transferred to" name'],
                        'receiver_bank'    => ['type' => 'STRING', 'description' => 'Beneficiary bank if shown, e.g. "Meezan" or "myABL"'],
                        'sender_account'   => ['type' => 'STRING', 'description' => 'From-account as shown, e.g. "**** ...4403"'],
                        'sender_name'      => ['type' => 'STRING', 'description' => 'From-account holder name'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'read_purchase_log',
                'description' => 'Read a WhatsApp PURCHASE LOG screenshot (a vendor group where the butcher posts each weighing, e.g. "Mutton 12.5", "Chakki . 650"). Call this when the image is a chat full of product+weight lines — NOT a bank transfer receipt. Pass the group title, participant names, and the lines grouped by the chat\'s own date separators. Returns, per day: the vendor, the priced lines ready to draft, whether that day is already recorded, and anything it could not place.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'group_title'  => ['type' => 'STRING', 'description' => 'Chat/group name at the top, e.g. "Taimoor Ali (Jilani Meat)"'],
                        'participants' => ['type' => 'STRING', 'description' => 'Participant names under the title, comma separated'],
                        'vendor_id'    => ['type' => 'INTEGER', 'description' => 'ONLY when the tool said it could not tell whose group this is and the user has ANSWERED: the vendor id from find_vendor. Never pass a guess — the user\'s answer is remembered for this group.'],
                        'days' => [
                            'type' => 'ARRAY',
                            'description' => 'One entry per date separator in the screenshot, in order',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'label' => ['type' => 'STRING', 'description' => 'The separator exactly as shown: "Saturday", "Yesterday", "Today", or a date'],
                                    'lines' => [
                                        'type' => 'ARRAY',
                                        'description' => 'Every message under that separator, in order',
                                        'items' => [
                                            'type' => 'OBJECT',
                                            'properties' => [
                                                'text' => ['type' => 'STRING', 'description' => 'Message text, e.g. "Mutton 5.750" (a photo caption counts)'],
                                                'time' => ['type' => 'STRING', 'description' => 'Time shown on the message, e.g. "9:15 am"'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['days'],
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
                'name' => 'remember_payee',
                'description' => 'Remember that a bank account / beneficiary name belongs to a particular vendor, one of our own accounts, or an expense category — so the NEXT transfer to it is recognised without asking. Use this whenever the user tells you who a beneficiary really is ("the Al Shifa Trust payments will be for ASTEH", "Imran Saeed is Imran Qureshi", "remember this account"). Pass the beneficiary account EXACTLY as the screenshot or SMS showed it (a masked fragment is fine and normal). This is the ONLY way to save that mapping — set_default cannot do it, so never claim you have remembered a payee unless this tool succeeded.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'account' => ['type' => 'STRING', 'description' => 'Beneficiary account as shown, e.g. "**** ...4237" or "PK16FAYSxx564". Strongly preferred — a rule keyed on the account is the reliable one.'],
                        'bank'    => ['type' => 'STRING', 'description' => 'Beneficiary bank if shown, e.g. "Meezan"'],
                        'name'    => ['type' => 'STRING', 'description' => 'Beneficiary name as shown, e.g. "AL SHIFA TRUST RAWALPINDI". Used alone only when no account is available, and then only as a suggestion.'],
                        'entity_type' => ['type' => 'STRING', 'description' => 'vendor | account | expense | ignore. "account" = one of OUR own accounts (money moved but is still ours). "ignore" = a personal/merchant charge to stop asking about.'],
                        'vendor_id' => ['type' => 'INTEGER', 'description' => 'Required for entity_type=vendor — the real id from find_vendor, never a guess.'],
                        'account_id' => ['type' => 'INTEGER', 'description' => 'Required for entity_type=account — an id from get_context.'],
                        'label' => ['type' => 'STRING', 'description' => 'For entity_type=expense or ignore: the expense category name, or what this merchant is.'],
                        'force' => ['type' => 'BOOLEAN', 'description' => 'Only after the tool reported a conflict AND the user confirmed moving the account to the new owner.'],
                    ],
                    'required' => ['entity_type'],
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
                'name' => 'find_employee',
                'description' => 'Look up an EMPLOYEE who can be given a salary advance. Returns only people who are on the Payroll screen (monthly AND custom-schedule), with their schedule, whether that month is already paid, whether they are on a running balance (khata), and any advances already open. ALWAYS call this before draft_salary_advance — never guess a user_id. Omit "query" to list everyone.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the employee name. Omit to list all.'],
                        'payroll_month' => ['type' => 'STRING', 'description' => 'YYYY-MM you intend to give the advance for. Omit for the current month. Only changes the "already paid" answer.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'draft_salary_advance',
                'description' => 'Prepare a SALARY ADVANCE to an employee for the user to confirm. This does NOT pay anything — it shows a confirmation card. Call find_employee first for a real user_id. '
                    . 'THE MONTH MATTERS: an advance is recovered from ONE month\'s pay and charged to that month. It defaults to the CURRENT month and the card always shows it — say which month you used in your reply so he can catch it. '
                    . 'If he then says a different month ("no, that was for August"), call this again with payroll_month AND replaces_draft_id so the old card is cancelled and he does not confirm the wrong one. '
                    . 'If he shares a transfer screenshot or a bank debit and says it was an advance, use read_transfer_screenshot for the amount/bank, then draft it here — the image is attached to the card automatically. '
                    . 'Only the current month or earlier is normal; one month ahead is allowed when this month is already paid.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'user_id' => ['type' => 'INTEGER', 'description' => 'Employee id from find_employee. Never guess it.'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Amount in PKR'],
                        'funding' => ['type' => 'STRING', 'description' => "'cash' (NF Cash) or 'online' (bank transfer). Defaults to cash."],
                        'bank_id' => ['type' => 'INTEGER', 'description' => 'Which of our banks it went from, when funding is online and he said so (or it came off a screenshot). If unknown, OMIT it — the card shows bank buttons for one-tap selection. Do not ask in text.'],
                        'payroll_month' => ['type' => 'STRING', 'description' => 'YYYY-MM the advance is deducted from. OMIT for the current month, which is the normal case.'],
                        'money_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD the money actually left, ONLY when payroll_month is a past month (entered late). Must be a day inside that month. Omit otherwise — it is today.'],
                        'note' => ['type' => 'STRING', 'description' => 'Optional note'],
                        'replaces_draft_id' => ['type' => 'INTEGER', 'description' => 'When CORRECTING a card he already has (a different month, amount or bank), pass that draft_id from get_pending_draft — the old card is cancelled so the wrong one cannot be confirmed by scrolling up.'],
                    ],
                    'required' => ['user_id', 'amount'],
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
                'description' => 'Prepare a vendor PURCHASE (stock/goods bought from a vendor — increases what we owe them; no money moves). Shows a confirmation card. Call find_vendor first for the real vendor_id. Use this when the user says "purchased/bought/khareeda", NOT for paying a vendor. WHENEVER the user names products and weights — typed, spoken, or read off a slip photo ("13.5 mutton whole", "89 veal raan haddi and 1.2 veal mix") — pass them as `items` and NEVER work out a total yourself: I price every line from that vendor\'s own catalogue. Only use a bare `amount` when the user gives you a lump sum with no products.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'vendor_id' => ['type' => 'INTEGER', 'description' => 'From find_vendor'],
                        'amount' => ['type' => 'NUMBER', 'description' => 'Purchase amount in PKR. For a weighed day pass the day\'s total from read_purchase_log — it is recomputed from _lines anyway.'],
                        'transaction_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD. Omit for today.'],
                        'description' => ['type' => 'STRING', 'description' => 'Optional note, e.g. what was bought'],
                        'group_title' => ['type' => 'STRING', 'description' => 'When drafting from a WhatsApp purchase log: the group title exactly as passed to read_purchase_log. On confirm the group is remembered as this vendor\'s.'],
                        'items' => [
                            'type' => 'ARRAY',
                            'description' => 'Products and weights as the user gave them. I look the rate up from the vendor\'s catalogue — do NOT pass a rate you inferred, and do NOT also compute `amount`. Anything I cannot match, the card asks about with buttons.',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'product'  => ['type' => 'STRING', 'description' => 'The product words exactly as the user said them, e.g. "mutton whole", "veal raan haddi wali", "chakki"'],
                                    'quantity' => ['type' => 'NUMBER', 'description' => 'Weight/count for this line, e.g. 13.5'],
                                    'rate'     => ['type' => 'NUMBER', 'description' => 'ONLY when the user explicitly states a price for this line ("cow brain 350"). Omit otherwise — the catalogue rate is used.'],
                                ],
                                'required' => ['product', 'quantity'],
                            ],
                        ],
                        '_lines' => [
                            'type' => 'ARRAY',
                            'description' => 'Weighed lines from read_purchase_log, passed through EXACTLY as returned — one per weighing, never summed, each keeping its text.',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'product_id'   => ['type' => 'INTEGER', 'description' => 'Vendor product id, exactly as read_purchase_log returned it'],
                                    'product_name' => ['type' => 'STRING'],
                                    'unit'         => ['type' => 'STRING'],
                                    'quantity'     => ['type' => 'NUMBER'],
                                    'rate'         => ['type' => 'NUMBER'],
                                    'rate_varies'  => ['type' => 'BOOLEAN'],
                                    'text'         => ['type' => 'STRING', 'description' => 'The chat message this line came from'],
                                ],
                            ],
                        ],
                        '_unplaced' => [
                            'type' => 'ARRAY',
                            'description' => 'Weighings read_purchase_log could not place, passed through as returned — the card asks about them with chips and cannot be confirmed until answered.',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'text'     => ['type' => 'STRING'],
                                    'quantity' => ['type' => 'NUMBER'],
                                ],
                            ],
                        ],
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
                'read_transfer_screenshot' => $this->readTransferScreenshot($args, $user),
                'read_purchase_log'    => $this->readPurchaseLog($args, $user),
                'find_employee'        => $this->findEmployee($args, $user),
                'find_customer'        => $this->findCustomer($args, $user),
                'list_expenses'        => $this->listExpenses($args, $user),
                'find_order'           => $this->findOrder($args, $user),
                'list_customer_invoices' => $this->listCustomerInvoices($args, $user),
                'set_default'          => $this->setDefault($args, $user),
                'remember_payee'       => $this->rememberPayee($args, $user),
                'draft_expense'         => $this->drafts->draftExpense($args, $user),
                'draft_vendor_payment'  => $this->drafts->draftVendorPayment($args, $user),
                'draft_vendor_purchase' => $this->drafts->draftVendorPurchase($args, $user),
                'draft_shop_payment'    => $this->drafts->draftShopPayment($args, $user),
                'draft_account_transfer' => $this->drafts->draftAccountTransfer($args, $user),
                'draft_payment_proof'   => $this->drafts->draftPaymentProof($args, $user),
                'draft_salary_advance'  => $this->drafts->draftSalaryAdvance($args, $user),
                default                => ['error' => "Unknown tool: {$name}"],
            };
        } catch (\Throwable $e) {
            Log::error('Assistant tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['error' => 'That lookup failed: ' . $e->getMessage()];
        }
    }

    // ── READ TOOLS ───────────────────────────────────────────────────────────

    /**
     * Employees who can be given a salary advance — resolved through PayrollService so this
     * is exactly the population the Payroll screen shows (monthly AND custom-schedule), and
     * the assistant can never offer someone the grid would not.
     *
     * Each row carries the two facts that decide whether an advance is even possible, so the
     * model can say so in words instead of drafting a card that would be refused: whether the
     * month is already paid, and whether they are on a running balance (khata employees take
     * a PAYMENT against their balance, never an advance).
     */
    private function findEmployee(array $args, $user): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $month = trim((string) ($args['payroll_month'] ?? '')) ?: null;
        if ($month !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return ['error' => 'payroll_month must be YYYY-MM.'];
        }

        $rows = (new \App\Services\HR\PayrollService())
            ->advanceEligibleEmployees($query !== '' ? $query : null, $month);

        if (!$rows) {
            return $query !== ''
                ? ['matches' => [], 'note' => 'Nobody on the Payroll screen matches "' . $query . '".']
                : ['matches' => [], 'note' => 'No employees are on the Payroll screen.'];
        }

        return [
            'month' => $month ?: now()->format('Y-m'),
            'matches' => array_map(fn($e) => [
                'user_id'            => $e['user_id'],
                'name'               => $e['name'],
                'schedule'           => $e['schedule'],
                'month_already_paid' => $e['month_paid'],
                'running_balance'    => $e['balance_tracked'],
                'open_advances'      => $e['open_advance_total'],
            ], $rows),
        ];
    }

    /**
     * Everything the model needs to turn "record 5000 fuel" into real ids.
     * One call instead of four: fewer round trips = faster + cheaper, and the
     * model can't half-populate a draft from a partial picture.
     */
    private function getContext($user): array
    {
        // ⚠ ASK PaymentSourceService — never query t_fin_accounts here.
        //
        // This used to be a raw query filtered only on is_private, so the model was
        // shown every company account regardless of business unit or of the account
        // tags that actually decide who may pay from what. On Aug-5-2026 that put
        // Taimur's Online Bank on a FROZEN expense card, he confirmed it, and the
        // server 403'd him after the fact — the exact failure the service exists to
        // prevent ("a picker that offers an account the server will reject is worse
        // than no picker at all"). The assistant is a picker.
        //
        // Each account carries WHICH business units it may fund, per purpose,
        // because that pairing is the rule: a tagged account funds every unit the
        // user can file against, an untagged fallback only its own. The model must
        // send a pair the lists agree on.
        $paySvc = app(\App\Services\FIN\PaymentSourceService::class);

        $unitRows = \App\Models\FIN\AccountModel::getUserAccessibleBusinessUnits()
            ->filter(fn($u) => (int) ($u->is_active ?? 1) === 1);

        $accounts = [];
        foreach ($unitRows as $unit) {
            foreach ([
                'expense' => \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE,
                'vendor'  => \App\Services\FIN\PaymentSourceService::PURPOSE_VENDOR,
            ] as $key => $purpose) {
                foreach ($paySvc->sourcesFor($user, (int) $unit->id, $purpose) as $src) {
                    $id = (int) $src['id'];
                    if (!isset($accounts[$id])) {
                        $accounts[$id] = [
                            'id' => $id,
                            'code' => $src['code'],
                            'name' => $src['name'],
                            'is_bank' => (bool) $src['is_online'],
                            // Owner ruling: qurbani is a once-a-year flow. Flag the rows so
                            // a plain "online"/"cash" can never land on a QURBANI_* account.
                            'qurbani_only' => $this->mentionsQurbani($src['name'] . ' ' . $src['code']) ?: null,
                            'for_expense' => [],
                            'for_vendor'  => [],
                        ];
                    }
                    $accounts[$id]['for_' . $key][] = (int) $unit->id;
                }
            }
        }
        $accounts = array_values(array_map(
            fn($a) => array_filter($a, fn($v) => $v !== null && $v !== []),
            $accounts
        ));

        $banks = DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'short_code'])
            ->map(fn($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->short_code])
            ->all();

        // Only the books this person may actually file against — offering the rest
        // is the same bug in a different field.
        $units = $unitRows->map(fn($u) => ['id' => (int) $u->id, 'name' => $u->name, 'code' => $u->code])
            ->values()->all();

        // Real categories people actually use, so the model reuses existing
        // spellings instead of inventing "Petrol" alongside "Fuel/Petrol".
        // ⚠ EVERY category, not the top 25 (owner-reported, Aug-2026). Taimur
        // said "Vaccum seal bags" FOUR times on 19-Aug and each time it was
        // filed as "Packaging - Bags" — the category exists and he had used it
        // five times, but it ranks 28th, so the model was literally never shown
        // it and could not have got this right. There are ~60 of them: short
        // strings, and being able to see the rare ones is the whole point.
        $categories = DB::table('t_req_master')
            ->whereNotNull('expense_category')
            ->where('expense_category', '!=', '')
            ->select('expense_category', DB::raw('COUNT(*) c'))
            ->groupBy('expense_category')
            ->orderByDesc('c')
            ->limit(200)
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
                . 'Accounts flagged qurbani_only are for the once-a-year Qurbani flow ONLY — never use them unless the user literally says "qurbani". '
                . 'payment_source_accounts[].for_expense / for_vendor list the business_unit ids that account may pay for. '
                . 'The account and the business unit must AGREE: if the user names a business unit, only offer accounts whose list contains it, '
                . 'and if their saved default account cannot pay for that unit, say so and offer one that can — the server rejects the rest.',
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

        // THREE different questions can live in the choice slot now — telling
        // the model "bank buttons" while the card is asking which TRANSFERS to
        // drop, or WHICH PRODUCT a weighing was, would have it instruct the
        // user to tap a bank that isn't on screen.
        $payload = json_decode($d->payload_json, true) ?: [];
        $choice  = $payload['_pending_choice'] ?? null;
        $choiceField = is_array($choice) ? ($choice['field'] ?? null) : null;

        return [
            'pending_draft' => [
                'draft_id' => $d->id,
                'type' => $d->type,
                'summary' => $d->summary,
                'details' => $payload,
                'awaiting_bank_choice' => $choice !== null && !in_array($choiceField, ['_drop_transfer', '_place_line'], true),
                'awaiting_transfer_drop' => $choiceField === '_drop_transfer',
                'awaiting_line_place' => $choiceField === '_place_line',
                'pending_question' => is_array($choice) ? ($choice['label'] ?? null) : null,
            ],
            'note' => 'A card IS waiting on screen. Confirmation happens by TAPPING the Confirm button on it — a chat "yes" does nothing. Tell the user to tap Confirm on the card; if awaiting_bank_choice, to pick the bank on it first; if awaiting_transfer_drop, that these transfers total more than we owe and they should first tap-drop any already recorded; if awaiting_line_place, the card is asking WHICH PRODUCT one weighing was — tell him to tap it on the card (pending_question is the exact ask), or if he ANSWERS in chat, re-draft via replaces_draft_id with that line moved from _unplaced into _lines keeping its text. To CHANGE something, call the draft tool again with the corrected details.',
        ];
    }

    // ── PREFERENCES (owner ruling: Taimur sets his own defaults) ─────────────

    /**
     * Read a transfer screenshot: which way, and who is the other side.
     *
     * The answer is deliberately shaped as an INSTRUCTION rather than raw data.
     * The model's job here is to route — vendor payment, customer proof, or a
     * question — and the one mistake that actually costs money is treating a
     * customer's proof as a payment we made. So the direction is decided from
     * our own bank registry, not from the model's reading, and 'unknown' says
     * plainly that it must ask.
     */
    private function readTransferScreenshot(array $args, $user): array
    {
        $resolver = app(\App\Services\Assistant\ScreenshotPayeeResolver::class);

        $recvAcct = trim((string) ($args['receiver_account'] ?? '')) ?: null;
        $recvName = trim((string) ($args['receiver_name'] ?? '')) ?: null;
        $recvBank = trim((string) ($args['receiver_bank'] ?? '')) ?: null;
        $sendAcct = trim((string) ($args['sender_account'] ?? '')) ?: null;
        $sendName = trim((string) ($args['sender_name'] ?? '')) ?: null;

        $direction = $resolver->direction($recvAcct, $sendAcct, $recvName, $sendName);

        if ($direction === $resolver::DIR_IN) {
            return [
                'direction' => 'in',
                'note' => 'Money came INTO our account — this is a CUSTOMER paying us, not a payment we made. '
                        . 'Use find_customer then draft_payment_proof (or draft_shop_payment for a shop). '
                        . 'NEVER draft a vendor payment from this.',
            ];
        }

        if ($direction === $resolver::DIR_UNKNOWN) {
            return [
                'direction' => 'unknown',
                'note' => 'I cannot tell from this whether the money went out or came in — neither side is an '
                        . 'account of ours that I recognise. ASK the user in one short question whether they '
                        . 'PAID someone or RECEIVED this, and do not draft anything until they answer.',
            ];
        }

        // ⭐ WHICH of our banks the money left. The screenshot names the sending
        // account, so the card can carry the bank pre-filled instead of asking
        // him to tap it — he hit that needless tap twice in the 19–22 Aug logs.
        $fromBank = $resolver->ourBankAccount($sendAcct);
        $bankNote = $fromBank
            ? ' It was paid from our ' . $fromBank['name'] . ' — pass paid_from_bank_account_id as '
              . 'receiving_account_id on the draft so the bank is already answered.'
            : '';

        // Money OUT — try to name the beneficiary.
        $payee = $resolver->resolvePayee($recvAcct, $recvBank, $recvName);
        if (!$payee) {
            return array_filter([
                'direction' => 'out',
                'payee' => null,
                'paid_from_bank_account_id' => $fromBank['id'] ?? null,
                'paid_from_bank' => $fromBank['name'] ?? null,
                'note' => 'Money went OUT of our account, but this beneficiary account is not one I have been '
                        . 'taught. ASK who was paid. Once they tell you, draft it as usual — and afterwards the '
                        . 'user can save the account against that vendor so it is recognised next time.' . $bankNote,
            ], fn($v) => $v !== null);
        }

        $out = [
            'direction'   => 'out',
            'payee'       => $payee,
            'paid_from_bank_account_id' => $fromBank['id'] ?? null,
            'paid_from_bank' => $fromBank['name'] ?? null,
            'matched_by'  => $payee['how'],
        ];

        if ($payee['entity_type'] === 'vendor') {
            $v = DB::table('t_fin_vendors as v')
                ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'v.account_id')
                ->where('v.id', $payee['entity_id'])
                ->first(['v.id', 'v.vendor_name', 'a.current_balance']);
            $out['vendor_id'] = $v->id ?? null;
            $out['vendor_name'] = $v->vendor_name ?? $payee['label'];
            $out['outstanding'] = $v ? round((float) ($v->current_balance ?? 0), 0) : null;
            $out['note'] = 'Money went OUT to ' . ($v->vendor_name ?? $payee['label'])
                . ' — a vendor we know by this account. Draft the vendor payment with the amount and date you '
                . 'read off the image, and SAY who you matched it to so the user can correct you. '
                . 'Nothing is recorded until they tap Confirm.' . $bankNote;
        } elseif ($payee['entity_type'] === 'account') {
            $out['to_account_id'] = $payee['entity_id'];
            $out['note'] = 'Money went OUT to ' . $payee['label'] . ' — one of OUR OWN accounts, so this is a '
                . 'transfer, not a payment. Use draft_account_transfer.';
        } else { // expense category
            $out['expense_category'] = $payee['label'];
            $out['note'] = 'Money went OUT and this account is remembered as the expense category "'
                . $payee['label'] . '". Draft the expense with that category and the amount/date from the image.'
                . $bankNote;
        }

        return $out;
    }

    /**
     * Read a WhatsApp purchase-log screenshot into ready-to-draft days.
     *
     * Returns instructions rather than raw data, because the mistakes that cost
     * money here are all decisions: recording a day that is already on the
     * books, or putting a day's meat on the wrong vendor's khata. So the tool
     * resolves what it can prove and says plainly where it needs an answer.
     */
    private function readPurchaseLog(array $args, $user): array
    {
        $svc = app(\App\Services\Assistant\PurchaseLogService::class);

        $title = trim((string) ($args['group_title'] ?? ''));
        $participants = array_filter(array_map('trim',
            explode(',', (string) ($args['participants'] ?? ''))));

        // ⭐ HIS ANSWER BEATS EVERY GUESS — and every old lesson. When the group
        // could not be recognised, the model asks and calls again with the
        // vendor he named; when a group was mis-taught, this same path is how
        // it gets corrected (confirm re-teaches the key to the new vendor).
        $vendor = null;
        $toldId = (int) ($args['vendor_id'] ?? 0);
        if ($toldId > 0) {
            $name = DB::table('t_fin_vendors')->where('id', $toldId)
                ->where('is_active', 1)->value('vendor_name');
            if (!$name) {
                return ['error' => 'Vendor id ' . $toldId . ' does not exist — use find_vendor, never guess.'];
            }
            $vendor = ['vendor_id' => $toldId, 'vendor_name' => $name, 'how' => 'told'];
        }

        $vendor = $vendor ?: $svc->resolveVendor($title, $participants);
        if (!$vendor) {
            return [
                'vendor' => null,
                'note' => 'I cannot tell which vendor this group belongs to' . ($title !== '' ? ' ("' . $title . '")' : '')
                    . '. ASK which vendor it is (find_vendor to get the id), then call this tool AGAIN with the '
                    . 'same days PLUS vendor_id — after he confirms, the group is remembered for next time.',
            ];
        }

        $out = [
            'vendor' => ['id' => $vendor['vendor_id'], 'name' => $vendor['vendor_name'], 'matched_by' => $vendor['how']],
            'days'   => [],
        ];

        foreach (($args['days'] ?? []) as $day) {
            $label = (string) ($day['label'] ?? '');
            $date  = $this->resolveLogDate($label);
            if (!$date) {
                $out['days'][] = ['label' => $label, 'date' => null, 'action' => 'ask_date',
                    'note' => 'I could not work out the real date for "' . $label . '" — ask him which date it is.'];
                continue;
            }

            $parsed = $svc->parseLines($vendor['vendor_id'], $day['lines'] ?? []);
            if (empty($parsed['lines']) && empty($parsed['unknown'])) {
                continue; // a separator with only chatter under it
            }

            $verdict = $svc->dedupeVerdict($vendor['vendor_id'], $date, $parsed['lines'], $parsed["unknown"]);
            $total = round(array_sum(array_map(fn($l) => $l['quantity'] * $l['rate'], $parsed['lines'])), 2);

            $entry = [
                'label'    => $label,
                'date'     => $date,
                'lines'    => array_map(fn($l) => [
                    'product_id'   => $l['product_id'],
                    'product_name' => $l['product_name'],
                    'unit'         => $l['unit'],
                    'quantity'     => $l['quantity'],
                    'rate'         => $l['rate'],
                    'rate_varies'  => $l['rate_source']['varies'] ?? false,
                    // The chat message itself — MUST travel into _lines so a
                    // confirm can learn placements and corrections (WAPROD).
                    'text'         => $l['text'] ?? '',
                ], $parsed['lines']),
                'total'    => $total,
                'existing' => $verdict['existing'],
            ];

            // Anything whose product we could not place — chips, never a guess.
            if (!empty($parsed['unknown'])) {
                $entry['unplaced'] = $parsed['unknown'];
                $entry['products'] = array_map(fn($p) => ['id' => (int) $p->id, 'name' => $p->product_name],
                    $svc->vendorProducts($vendor['vendor_id']));
            }

            $entry['action'] = match ($verdict['verdict']) {
                'skip'     => 'skip',
                'ask_same' => 'ask',
                'ask_near' => 'ask',
                default    => 'draft',
            };
            $entry['note'] = match ($verdict['verdict']) {
                'skip' => $date . ' is ALREADY recorded (Rs ' . number_format($verdict['existing']['amount'], 0)
                        . ' · ' . $verdict['existing']['lines'] . ' lines). Do NOT draft it — say so in one short line. '
                        . 'Only draft it if he explicitly says to add it anyway.',
                'ask_same' => 'There is already a purchase for ' . $date . ' (Rs '
                        . number_format($verdict['existing']['amount'], 0) . ' · ' . $verdict['existing']['summary']
                        . ') but it looks DIFFERENT from this. ASK whether this is a second purchase that day or a correction — do not draft until he answers.',
                'ask_near' => 'Nothing is recorded for ' . $date . ', but ' . $verdict['existing']['date']
                        . ' has a similar purchase (Rs ' . number_format($verdict['existing']['amount'], 0)
                        . ' · ' . $verdict['existing']['summary'] . '). ASK: same purchase, or a new day? Do not draft until he answers.',
                default => 'Nothing like this is recorded. Draft it with draft_vendor_purchase, passing vendor_id, '
                        . 'transaction_date = ' . $date . ', and _lines exactly as given here.',
            };

            $out['days'][] = $entry;
        }

        if (empty($out['days'])) {
            $out['note'] = 'I could not find any weighings in that screenshot — ask him what it is.';
            return $out;
        }

        $out['how_to_record'] = 'ONE card per day, oldest first. Each card is draft_vendor_purchase with _lines '
            . 'passed through EXACTLY as given (never one summed amount — and keep each line\'s text), plus _unplaced '
            . 'and group_title when present. Rates come from the vendor catalogue; a line marked rate_varies has been '
            . 'bought at different prices before, so read it out and let him correct it — a reply like "cow brain 350" '
            . 'means re-draft that card with the corrected rate via replaces_draft_id. If he says a LINE is the wrong '
            . 'product ("chakki was mutton whole"), re-draft with that line\'s product corrected, keeping its text — '
            . 'on confirm I learn what his word means for this vendor. The screenshot attaches itself.';
        if ($vendor['how'] === 'name_match') {
            $out['remember_group'] = 'This group was matched by NAME, not remembered. After he confirms the vendor is right, '
                . 'you may tell him it will be recognised automatically next time.';
        }
        if ($vendor['how'] === 'told') {
            $out['remember_group'] = 'Using the vendor HE named. Pass group_title on each draft — when he confirms, '
                . 'this group is remembered as ' . $vendor['vendor_name'] . ' and will not be asked again.';
        }

        return $out;
    }

    /**
     * A WhatsApp separator → a real date. The separators are RELATIVE to when
     * the screenshot was taken, so "Yesterday" is only yesterday if he forwards
     * it the same day — he is typically 1–3 days behind. Weekday names resolve
     * to the most recent past occurrence; anything unrecognised returns null so
     * the caller asks rather than invents a date for a stock entry.
     */
    private function resolveLogDate(string $label): ?string
    {
        $l = mb_strtolower(trim($label));
        if ($l === '') {
            return null;
        }
        if ($l === 'today')     return now()->toDateString();
        if ($l === 'yesterday') return now()->subDay()->toDateString();

        $days = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
                 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        foreach ($days as $name => $dow) {
            if (str_contains($l, $name)) {
                $d = now()->startOfDay();
                for ($i = 1; $i <= 7; $i++) {          // most recent PAST occurrence
                    $d = $d->copy()->subDay();
                    if ((int) $d->dayOfWeek === $dow) {
                        return $d->toDateString();
                    }
                }
            }
        }
        try {
            $parsed = \Illuminate\Support\Carbon::parse($label);
            return $parsed->isFuture() ? null : $parsed->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Remember who a beneficiary account/name really is.
     *
     * ⭐ WHY THIS EXISTS (owner-reported, Aug-2026). Al Shifa Trust was filed as
     * a charity expense two days running when it is really the vendor ASTEH.
     * Taimur said "save this account for the future" and the model reached for
     * set_default — which can only store default payment SOURCES — and then
     * told him "I have linked Al Shifa to ASTEH". Nothing was written anywhere,
     * so the third mis-file was guaranteed. There was no tool that could do
     * what he asked; now there is, and the prompt forbids claiming otherwise.
     *
     * Writes the SAME `t_ai_counterparty_map` rules the review panel manages,
     * so anything taught here shows up on the vendor screen and can be
     * deactivated there.
     */
    private function rememberPayee(array $args, $user): array
    {
        $type = strtolower(trim((string) ($args['entity_type'] ?? '')));
        if (!in_array($type, ['vendor', 'account', 'expense', 'ignore'], true)) {
            return ['error' => 'entity_type must be one of: vendor, account, expense, ignore.'];
        }

        // The entity must exist — an orphan rule renders as "vendor #57" and
        // silently recognises nothing.
        $entityId = null;
        $label = trim((string) ($args['label'] ?? '')) ?: null;

        if ($type === 'vendor') {
            $entityId = (int) ($args['vendor_id'] ?? 0);
            $name = $entityId ? DB::table('t_fin_vendors')->where('id', $entityId)->value('vendor_name') : null;
            if (!$name) {
                return ['error' => 'That vendor id does not exist. Use find_vendor first — never guess an id.'];
            }
            $label = $name;
        } elseif ($type === 'account') {
            $entityId = (int) ($args['account_id'] ?? 0);
            $name = $entityId ? DB::table('t_fin_accounts')->where('id', $entityId)->value('account_name') : null;
            if (!$name) {
                return ['error' => 'That account id does not exist. Use get_context for the real ids.'];
            }
            $label = $name;
        } elseif ($type === 'expense' && !$label) {
            return ['error' => 'For an expense rule I need the category name in `label`.'];
        }

        $rawAccount = trim((string) ($args['account'] ?? ''));
        $rawName    = trim((string) ($args['name'] ?? ''));
        $key = $rawAccount !== ''
            ? app(\App\Services\Assistant\BankSmsParser::class)->normalizeKey($rawAccount)
            : null;

        // ⚠⚠ SCREENSHOT MASKS ARE NOT SMS MASKS. normalizeKey is built for what
        // a bank SMS carries (MBL*2969, PK16FAYSxx564) and returns NULL for the
        // dotted form a bank APP prints — "**** ...4237" — which is precisely
        // what this tool is usually handed. Without this the rule silently
        // downgraded to name-only: it would look saved and then never fire.
        // A bare tail is the same shape the parser already produces for "*8303",
        // and the structural matcher reads it correctly (no bank token = no
        // contradiction, tails compared suffix-wise).
        if (!$key && $rawAccount !== '' && preg_match('/(\d{4,})\D*$/', $rawAccount, $m)) {
            $key = '*' . substr($m[1], -6);
        }

        // A FULL unmasked IBAN saves fine and then never fires — SMS and
        // screenshots only ever carry masked fragments. Same refusal the web
        // panel gives, for the same reason.
        if ($key && preg_match('/^PK\d{2}[A-Z]{4}[0-9]{14,}$/', $key)) {
            return ['error' => 'That looks like a full account number. Bank messages only show a masked fragment (e.g. PK96UNILxx322), so a rule on the full number would never match. Use the account exactly as the screenshot showed it.'];
        }
        if (!$key && $rawName === '') {
            return ['error' => 'I need the beneficiary account (as shown) or at least the beneficiary name to remember this by.'];
        }

        $map = app(\App\Services\Assistant\SmsCounterpartyMap::class);

        // ⚠ NEVER silently steal an account already taught to somebody else —
        // that would misroute every future transfer to it. Say who owns it and
        // let the user decide.
        if ($key && empty($args['force'])) {
            $existing = $map->byAccount($key);
            if ($existing
                && !($existing->entity_type === $type && (int) $existing->entity_id === (int) $entityId)) {
                return [
                    'conflict' => true,
                    'message'  => 'That account is already remembered as ' . $map->entityName($existing)
                        . ' (' . $existing->entity_type . '). Tell the user and ask whether to move it, '
                        . 'then call remember_payee again with force=true if they confirm.',
                    'current'  => ['entity_type' => $existing->entity_type, 'name' => $map->entityName($existing)],
                    'saved'    => false,
                ];
            }
        }

        $id = $map->save($key, $rawName ?: null, $type, $entityId, $label, (int) $user->id);
        if (!$id) {
            return ['error' => 'I could not save that — I need a readable account fragment or name.'];
        }

        // How many PAST messages this rule would have caught. A key that matches
        // nothing is usually a typo, and saying so now beats discovering it in
        // three weeks when the suggestion never appears.
        $pastHits = 0;
        if ($key) {
            try {
                $pastHits = (int) DB::table('t_ai_bank_sms')->where('counterparty_account', $key)->count();
            } catch (\Throwable $e) {
                $pastHits = 0;
            }
        }

        return [
            'saved'       => true,
            'remembered'  => $label ?: $rawName,
            'matched_on'  => $key ? 'account ' . $key : 'name only',
            'past_messages_matching' => $pastHits,
            'note' => $key
                ? 'Saved. Tell the user in ONE short line that transfers to this account will be recognised as '
                    . ($label ?: $rawName) . ' from now on.'
                    . ($pastHits === 0 ? ' It matches no past message, so if they expected it to, the account fragment may differ from what the bank sends — mention that briefly.' : '')
                : 'Saved as a NAME-only rule, which is weaker — names collide, so it will be offered as a suggestion rather than acted on automatically. '
                    . 'Tell the user it is remembered, and that giving you the account number from a screenshot would make it certain.',
        ];
    }

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

        // ENUM preferences are settled first: they are not ids and would
        // otherwise fall through to the account/business-unit resolvers below
        // and be rejected as "no such account".
        $enums = config('assistant.pref_enums', []);
        if (isset($enums[$key])) {
            $picked = strtolower(trim($value));
            if (!in_array($picked, $enums[$key], true)) {
                return ['error' => 'For ' . $key . ' use one of: ' . implode(', ', $enums[$key]) . '.'];
            }
            $prefs = $this->prefs($user->id);
            $prefs[$key] = $picked;
            DB::table('t_ai_user_prefs')->updateOrInsert(
                ['user_id' => $user->id],
                ['prefs_json' => json_encode($prefs), 'updated_at' => now()]
            );
            // `resolved_to` is what the settings endpoint echoes back as
            // "Saved: …", so an enum must supply it like every other pref.
            return ['saved' => true, 'key' => $key, 'value' => $picked, 'resolved_to' => $picked];
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
