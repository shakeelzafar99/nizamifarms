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
    // unpaid/partial online orders. Per product decision: 30 days.
    'match_window_days' => env('PAYMENT_SIGNALS_MATCH_WINDOW_DAYS', 30),

    // A payment can only belong to an order that already EXISTED when the
    // money was sent. We therefore never match a payment to an order whose
    // order_date is AFTER the payment date. This small grace (days) absorbs
    // timezone / clock skew between the bank receipt and our order timestamps.
    'match_future_grace_days' => env('PAYMENT_SIGNALS_FUTURE_GRACE_DAYS', 1),

    // When corroborating a WhatsApp screenshot with a bank email (or vice
    // versa), the two must describe the SAME payment — same amount AND a
    // transaction time within this many days of each other.
    'pair_window_days' => env('PAYMENT_SIGNALS_PAIR_WINDOW_DAYS', 3),

    // Amount tolerance (in PKR) for an "exact" balance match. Bank rounding
    // and tip handling can cause sub-rupee differences.
    'amount_tolerance' => env('PAYMENT_SIGNALS_AMOUNT_TOLERANCE', 1.00),

    // Order payment methods we consider "online" for matching purposes.
    'online_payment_methods' => ['online', 'bank_transfer'],

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
