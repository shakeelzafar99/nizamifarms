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

    // When corroborating a WhatsApp screenshot with a bank email (or vice
    // versa), the two must describe the SAME payment — same amount AND a
    // transaction time within this many days of each other.
    'pair_window_days' => env('PAYMENT_SIGNALS_PAIR_WINDOW_DAYS', 3),

    // Amount tolerance (in PKR) for treating a payment as matching an order's
    // balance. Widened to 10 PKR (Jun-2026): customers routinely round or
    // over/under-pay by a few rupees, and bank receipts carry small fee/rounding
    // differences. A payment within ±10 of a single invoice's balance — OR within
    // ±10 of the COMBINED total of several open invoices — is treated as a clean
    // match (so it reads "Proof received"/"Verified", not "amount differs").
    // Applies to single-invoice matching, combined/bulk matching, and the
    // WhatsApp⇄bank-email pairing. Override per-environment via the env key.
    'amount_tolerance' => env('PAYMENT_SIGNALS_AMOUNT_TOLERANCE', 10.00),

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
];
