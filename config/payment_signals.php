<?php

/*
|--------------------------------------------------------------------------
| Online Payment Auto-Matching (Payment Signals)
|--------------------------------------------------------------------------
|
| Configuration for the "smart sticky-notes" feature: read WhatsApp bank
| screenshots (Gemini Vision) and bank confirmation emails (IMAP + regex),
| extract the payment facts, and DECORATE the existing Online Approvals,
| Open Orders, Messages, and Daily Closing screens.
|
| This feature NEVER writes to the ledger, order payments, or balances.
| It only reads existing tables and stores extracted facts in
| t_fin_payment_signal so the UI can show confidence badges.
|
*/

return [

    // Master switch. When false, the scheduled commands self-throttle to a
    // near no-op (one tiny config read) and the WhatsApp webhook skips
    // creating signal rows. Flip to true once the .env keys are set.
    'enabled' => env('PAYMENT_SIGNALS_ENABLED', false),

    // Password for the /admin/payments/imap-check diagnostics webpage.
    // Visit that URL with ?key=<this value>. Leave blank to disable the page.
    'check_secret' => env('PAYMENT_SIGNALS_CHECK_SECRET', ''),

    // How many days back to look when matching a payment to the customer's
    // unpaid/partial online orders. Widened to 200 days so a payment can match
    // against effectively any still-open (unpaid/partial) order, not just the
    // last month. Override per-environment via PAYMENT_SIGNALS_MATCH_WINDOW_DAYS.
    'match_window_days' => env('PAYMENT_SIGNALS_MATCH_WINDOW_DAYS', 200),

    // A payment can only belong to an order that already EXISTED when the
    // money was sent. We therefore never match a payment to an order whose
    // order_date is AFTER the payment date. This small grace (days) absorbs
    // timezone / clock skew between the bank receipt and our order timestamps.
    'match_future_grace_days' => env('PAYMENT_SIGNALS_FUTURE_GRACE_DAYS', 1),

    // When corroborating a WhatsApp screenshot with a bank confirmation (email
    // or a bank credit SMS, or vice versa), the two must describe the SAME
    // payment — same amount AND a transaction time within the DIRECTIONAL
    // window below. (A shared bank REFERENCE still pairs outright, ignoring
    // time — that path is unaffected.)
    //
    // The window is directional because a bank confirms AT the transaction,
    // while a customer's screenshot can be SENT days later (and if its receipt
    // time can't be OCR'd, the proof falls back to that later send time). So:
    //   • a screenshot may look up to pair_window_days BACK for its bank mate;
    //   • a bank confirmation may look up to pair_window_days FORWARD for its
    //     screenshot mate;
    //   • the opposite direction is limited to pair_slop_hours (clock slop),
    //     because a bank alert arriving days AFTER the payment it confirms is
    //     physically meaningless and only adds cross-pairing risk.
    'pair_window_days' => env('PAYMENT_SIGNALS_PAIR_WINDOW_DAYS', 3),
    'pair_slop_hours'  => env('PAYMENT_SIGNALS_PAIR_SLOP_HOURS', 24),

    // Amount tolerance (in PKR) for treating a payment as matching an order's
    // balance. Widened to 10 PKR (Jun-2026): customers routinely round or
    // over/under-pay by a few rupees, and bank receipts carry small fee/rounding
    // differences. A payment within ±10 of a single invoice's balance — OR within
    // ±10 of the COMBINED total of several open invoices — is treated as a clean
    // match (so it reads "Proof received"/"Verified", not "amount differs").
    // Applies to single-invoice matching and combined/bulk matching ONLY — i.e.
    // the customer's transfer vs their bill(s), where rounding is expected. It is
    // deliberately NOT used for screenshot⇄email pairing (see
    // pair_amount_tolerance below). Override per-environment via the env key.
    'amount_tolerance' => env('PAYMENT_SIGNALS_AMOUNT_TOLERANCE', 10.00),

    // Amount tolerance (in PKR) for CROSS-SOURCE pairing: deciding that a
    // WhatsApp screenshot and a bank credit-alert email describe the SAME bank
    // transaction (→ the "Verified" badge). DELIBERATELY tight and SEPARATE from
    // amount_tolerance above: a screenshot and the bank's own alert for one
    // transfer must report the same figure — the only allowable slack is
    // sub-rupee rounding / OCR display (e.g. "3,962" vs "3,962.00", or a 3,961.80
    // invoice paid as a round 3,962). A whole-rupee gap must NOT pair.
    // (Jul-2026: a stray Meezan credit of 3,926 wrongly paired to a 3,962
    // Easypaisa payment because pairing borrowed the loose 10 PKR tolerance,
    // producing a bogus "Rs 36 short — apply discount" prompt on NF-18447.)
    'pair_amount_tolerance' => env('PAYMENT_SIGNALS_PAIR_AMOUNT_TOLERANCE', 1.00),

    // How far BACK an inferred/guessed match may reach for a candidate order.
    // Deliberately much shorter than match_window_days (200): that generous
    // window is for evidence the customer supplied about their own account,
    // where an ancient unpaid invoice is a legitimate answer. A guess about an
    // UNIDENTIFIED payer has no such backing, so it only considers recent
    // trading. Pairs with match_future_grace_days on the forward side — see
    // PaymentSignalMatcher::guessOrderDateBounds().
    'guess_lookback_days' => env('PAYMENT_SIGNALS_GUESS_LOOKBACK_DAYS', 60),

    // Plausibility ceiling for the "nothing fits — attach to the newest open
    // order and flag it" fallback, applied to BANK-side signals only (email /
    // credit SMS). A credit larger than this multiple of the order's balance is
    // not that order's payment (Aug-2026: a Rs 400,000 credit vs Rs 1k–35k
    // orders), so it stays unattached and visible in the money inbox instead of
    // painting a nonsense proof. Historically only 17 of 1,114 matched signals
    // exceeded 2x, so 3x rejects only the absurd. Set 0 to disable the check.
    // WhatsApp screenshots are exempt — the customer sent that image on purpose.
    'mismatch_attach_max_ratio' => env('PAYMENT_SIGNALS_MISMATCH_MAX_RATIO', 3.0),

    // …and the FLOOR for the same fallback. A credit far below the invoice it
    // would land on is not a part-payment, it is a different transaction:
    // Aug-2026, Rs 2,250 read as Adnan Khan's and attached to his Rs 19,921
    // invoice (0.11x) because the payer name "MUHAMMAD ASLAM KHAN" shared two
    // common tokens with his alias. Measured on the full history: 95.2% of real
    // matches sit at 0.8-1.25x, and a 0.5 floor refuses exactly ONE bank-side
    // speculative attach — that one. Genuine part-payments (0.5-0.8x) survive,
    // as does the Nouman case (7,600 on a 7,400 balance = 1.03x). Set 0 to
    // disable. WhatsApp screenshots are exempt, same as the ceiling.
    'mismatch_attach_min_ratio' => env('PAYMENT_SIGNALS_MISMATCH_MIN_RATIO', 0.5),

    // A name token appearing in at least this SHARE of our customer/alias
    // corpus carries no identity, so two names agreeing only on such tokens is
    // not an identification (KHAN 6.65%, MUHAMMAD 5.61%, ALI 4.55% — against
    // ASLAM 0.61%, ADNAN 0.60%, NOUMAN 0.08%). Measured live, never hard-coded,
    // and it does NOT stop common surnames counting — it only stops them
    // counting ALONE. Set 0 to disable the check.
    'name_generic_token_share' => env('PAYMENT_SIGNALS_GENERIC_TOKEN_SHARE', 0.02),

    // Held bank credits are re-evaluated (the "resweep") because the invoice
    // they belong to is usually created AT DELIVERY — minutes to hours AFTER
    // the customer paid — so the first attempt at ingest often had nothing to
    // match against. Only credits younger than this are retried, and the sweep
    // itself is throttled so it costs a page-load nothing.
    'resweep_days'          => env('PAYMENT_SIGNALS_RESWEEP_DAYS', 7),
    'resweep_max_per_run'   => env('PAYMENT_SIGNALS_RESWEEP_MAX', 30),
    'resweep_throttle_mins' => env('PAYMENT_SIGNALS_RESWEEP_THROTTLE_MINS', 5),

    // How far an order's balance may sit from the credit and still be offered
    // to the AI payer-name arbiter as a CANDIDATE. Wider than amount_tolerance
    // on purpose: the arbiter only ever runs on credits the exact rules already
    // failed, which are precisely the ones a little off the invoice (the Nouman
    // case was Rs 200 over a Rs 7,400 order). The AI still only picks a NAME —
    // it never decides that an amount is close enough.
    'ai_arbiter_amount_slack' => env('PAYMENT_SIGNALS_AI_SLACK', 500),

    // Payer-name resolution (bank credit -> customer). Names shorter than
    // min_token_len are ignored as tokens, and a token-based match needs at
    // least min_tokens of them to agree — one shared surname ("chaudhry",
    // "khan", "ali") is never enough to name a payer in a 6,800-customer book
    // where 1,580 customers share a name with someone else.
    'name_min_tokens'   => env('PAYMENT_SIGNALS_NAME_MIN_TOKENS', 2),
    'name_min_token_len' => env('PAYMENT_SIGNALS_NAME_MIN_TOKEN_LEN', 3),

    // Bank-statement noise that is NOT a payer name. These arrive as the
    // "sender name" on some credit alerts and email bodies, and would otherwise
    // be learned as an alias for whoever happened to be approved that day —
    // "at the above address" had been attached to 125 different customers.
    'name_blacklist' => [
        'at the above address',
        'above address',
        'ca payroll account',
        'saadiq saver plus lcy',
        'ibft',
        'funds transfer',
        'cash deposit',
        'credit',
    ],

    // Order payment methods we consider "online" for matching purposes.
    'online_payment_methods' => ['online', 'bank_transfer'],

    // Should a candidate order be REQUIRED to carry an online/bank_transfer
    // payment_method? Default false: a customer who sends a bank-transfer
    // screenshot has paid online regardless of how the order was tagged at
    // creation (many delivered orders are created as the default 'cash' and
    // only paid online afterwards). When false we match purely on the
    // outstanding balance + date, which is what actually identifies the order.
    // Set true only if you want to restrict proof matching to orders already
    // marked online.
    'require_online_payment_method' => env('PAYMENT_SIGNALS_REQUIRE_ONLINE_METHOD', false),

    // Order payment statuses that are still owed money.
    'open_payment_statuses' => ['unpaid', 'partial'],

    /*
    |--------------------------------------------------------------------------
    | Gemini Vision (WhatsApp screenshot reading)
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'api_key'  => env('GEMINI_API_KEY', ''),
        'model'    => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout'  => env('GEMINI_TIMEOUT', 30),
        // Max signals to extract per scheduled run (cost + rate-limit guard).
        'batch_size' => env('PAYMENT_SIGNALS_GEMINI_BATCH', 15),
        // Stop retrying a screenshot after this many failed extraction attempts
        // (prevents a permanently-failing image from blocking the oldest-first
        // queue). Also bounds the one-time re-extract-for-bank backfill.
        'max_attempts' => env('PAYMENT_SIGNALS_GEMINI_MAX_ATTEMPTS', 5),
        // Max signals to RE-READ per tick for the bank-detection backfill (the
        // pending-approval proofs read under the old prompt). Runs AFTER live
        // extraction so it never crowds out new screenshots.
        'reextract_batch' => env('PAYMENT_SIGNALS_REEXTRACT_BATCH', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | IMAP (bank confirmation email reading)
    |--------------------------------------------------------------------------
    | Uses PHP's built-in imap_* functions — no Composer package required.
    | The host string is assembled as:
    |   {host:port/imap/encryption}  e.g. {box5443.bluehost.com:993/imap/ssl}
    */
    'imap' => [
        'host'       => env('IMAP_HOST', ''),
        'port'       => env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'), // ssl | tls | '' (none)
        'username'   => env('IMAP_USERNAME', ''),
        'password'   => env('IMAP_PASSWORD', ''),
        'folder'     => env('IMAP_FOLDER', 'INBOX'),
        // Validate the certificate? Bluehost shared certs sometimes need this
        // relaxed; set to false only if the connection fails on cert errors.
        'validate_cert' => env('IMAP_VALIDATE_CERT', true),
        // Max new emails to process per scheduled run.
        'batch_size' => env('PAYMENT_SIGNALS_EMAIL_BATCH', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-bank email recognition
    |--------------------------------------------------------------------------
    | Each entry maps a bank to: the sender addresses/patterns that identify
    | its credit-confirmation emails, and the parser class that extracts the
    | fields. Add a new bank by adding an entry + a parser class — no schema
    | or core code change. Ship Meezan first (matches the sample provided).
    |
    | 'from_contains'    — case-insensitive substrings; ANY match qualifies.
    | 'subject_contains' — optional; if set, ANY match must also be present.
    | 'parser'           — FQCN implementing the parser contract.
    | 'short_code'       — payer bank label stored on the signal.
    */
    'banks' => [
        'meezan' => [
            'from_contains'    => ['meezanbank.com', 'meezan'],
            'subject_contains' => [], // Meezan credit alerts vary; rely on body parse.
            'parser'           => \App\Services\Payments\Email\Parsers\MeezanCreditEmailParser::class,
            'short_code'       => 'MEEZAN',
        ],
        // Add more as you forward them, e.g.:
        // 'hbl' => [
        //     'from_contains' => ['hbl.com'],
        //     'subject_contains' => ['credit'],
        //     'parser' => \App\Services\Payments\Email\Parsers\HblCreditEmailParser::class,
        //     'short_code' => 'HBL',
        // ],
    ],

    // Our own receiving-bank name variants → matched against the email/screenshot
    // "to" side to confirm the money actually came to Nizami Farms.
    'our_account_name_hints' => ['nizami', 'nizami farms'],

    /*
    |--------------------------------------------------------------------------
    | Who may record a payment BY HAND
    |--------------------------------------------------------------------------
    | Owner ruling (Aug-2026): Shabib and Taimur only. A manual entry asserts
    | that money arrived when no proof did, so it is deliberately narrower than
    | the L1/L2 approval rights the rest of the payment screens use.
    |
    | Why an email list and not a role: Taimur has his own role, but Shabib
    | shares the "Management" role with the generic admin login, so no role can
    | express exactly these two people. The role check still runs alongside this
    | (see PaymentSignalsController::canRecordManualPayment), so putting someone
    | in a Taimur/Shabib role also works.
    |
    | Comma-separated. Case-insensitive. Change it here (or in .env) and run
    | `php artisan config:clear` — no code deploy needed.
    */
    'manual_entry_emails' => env(
        'PAYMENT_SIGNALS_MANUAL_ENTRY_EMAILS',
        'taimur@nizamifarms.com,shabib@nizamifarms.com'
    ),
];
